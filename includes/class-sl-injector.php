<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-destructive link injection via the WordPress `the_content` filter.
 *
 * Design goals
 * ────────────
 *   • Links appear in the rendered HTML (server-side, visible to
 *     Googlebot) but never touch wp_posts.
 *   • The post editor (Gutenberg / Classic) stays completely clean.
 *   • Nested links (<a> inside <a>) are impossible.
 *   • Configurable tag exclusion list is respected (h1–h6, pre, code …).
 *   • Polish / multibyte text is handled correctly throughout.
 *
 * Implementation
 * ──────────────
 *   Uses PHP's DOMDocument to walk the content tree.  Text nodes are
 *   scanned for the stored anchor phrase; the first occurrence is
 *   spliced into [textBefore] <a>anchor</a> [textAfter].
 *
 *   The processed HTML is cached per-post via WordPress Transients so
 *   the DOM parsing only runs once per TTL window.
 */
class SL_Injector {

	/** Transient key prefix (post_id appended). */
	private const CACHE_PREFIX = 'sl_inj_';

	/** Cache lifetime in seconds. */
	private const CACHE_TTL = 3600;

	/**
	 * HTML tags whose entire subtree is skipped during injection.
	 * Always includes <a> (enforced in code, not configurable).
	 *
	 * @var string[]
	 */
	private array $excluded_tags;

	public function __construct() {
		$this->excluded_tags = array_map(
			'strtolower',
			SL_Settings::get(
				'excluded_tags',
				[ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'script', 'style' ]
			)
		);

		add_filter( 'the_content', [ $this, 'inject' ], 20 );

		/* When a link record changes (reject / new insert) the cached
		 * injected HTML must be flushed so the change takes effect. */
		add_action( 'sl_link_changed', [ $this, 'flush_cache' ] );
	}

	/* ── the_content filter ─────────────────────────────────────── */

	/**
	 * Main entry point.  Called by WordPress for every piece of content
	 * rendered through the_content().
	 *
	 * @param string $content  Already-processed HTML (after wpautop etc.).
	 * @return string
	 */
	public function inject( string $content ): string {
		/* Only inject on single posts/pages (not archives, categories, etc.) */
		if ( ! is_singular() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		/* Serve from cache when available - validate before returning */
		$cached = get_transient( self::CACHE_PREFIX . $post_id );
		if ( $cached !== false && is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$links = SL_DB::get_links_for_post( $post_id, 'active' );
		if ( empty( $links ) ) {
			return $content;
		}

		/* Filter out links to non-published posts (trashed, draft, etc.)
		 * Custom URLs have target_post_id = 0, so allow those unconditionally */
		$links = array_filter( $links, function ( $link ) {
			// Custom URLs (external links) have target_post_id = 0 - always allow
			if ( (int) $link->target_post_id === 0 ) {
				return true;
			}
			// Internal links - check if target post exists and is published
			$target_post = get_post( $link->target_post_id );
			return $target_post && $target_post->post_status === 'publish';
		} );

		if ( empty( $links ) ) {
			return $content;
		}

		$result = $this->apply( $content, $links );

		set_transient( self::CACHE_PREFIX . $post_id, $result, self::CACHE_TTL );

		return $result;
	}

	/** Flush the cached injected content for one post. */
	public function flush_cache( int $post_id ): void {
		delete_transient( self::CACHE_PREFIX . $post_id );
	}

	/**
	 * Flush ALL injector caches (for bulk operations like delete_all_links).
	 *
	 * Note: WordPress doesn't provide a way to delete transients by prefix,
	 * so we query the database directly for efficiency.
	 */
	public static function flush_all_caches(): void {
		global $wpdb;

		// Delete all transients with our prefix
		// Use esc_like() to escape any LIKE wildcards in the prefix (defense in depth)
		$prefix = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s",
				$prefix,
				$timeout_prefix
			)
		);

		SL_Debug::log( 'injector', 'All injector caches cleared' );
	}

	/* ── DOM manipulation ───────────────────────────────────────── */

	/**
	 * Parse the HTML fragment, overlay every link, and return the
	 * modified fragment.
	 *
	 * @param string   $html
	 * @param object[] $links  Rows from wp_semantic_links.
	 * @return string
	 */
	private function apply( string $html, array $links ): string {
		/*
		 * DOMDocument needs a full document to parse; we wrap the
		 * fragment in a <div> with a unique ID so we can extract just
		 * its inner HTML afterwards.
		 */
		$wrap_id = 'sl-root-' . uniqid();
		$wrapped = "<div id=\"{$wrap_id}\">{$html}</div>";

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$doc->substituteCharacterReferences = false;

		libxml_use_internal_errors( true );
		$load_result = $doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $wrapped . '</body></html>'
		);

		/* Log any DOM parsing errors for debugging */
		$xml_errors = libxml_get_errors();
		if ( ! empty( $xml_errors ) ) {
			$error_messages = array_map( function( $e ) {
				return trim( $e->message ) . ' (line ' . $e->line . ')';
			}, array_slice( $xml_errors, 0, 5 ) );  // Limit to first 5 errors
			SL_Debug::log( 'injector', 'DOM parsing warnings', [
				'errors_count' => count( $xml_errors ),
				'first_errors' => $error_messages,
			] );
		}
		libxml_clear_errors();

		if ( ! $load_result ) {
			SL_Debug::log( 'injector', 'ERROR: DOM loadHTML failed completely' );
			return $html;
		}

		$root = $doc->getElementById( $wrap_id );
		if ( ! $root ) {
			SL_Debug::log( 'injector', 'ERROR: Could not find wrapper element in parsed DOM', [
				'wrap_id' => $wrap_id,
			] );
			return $html;
		}

		/* Inject each link (one replacement per link record) */
		foreach ( $links as $link ) {
			$this->inject_one( $root, $link->anchor_text, $link->target_url, $doc );
		}

		return $this->inner_html( $root );
	}

	/**
	 * Walk the subtree of $root looking for the first text node
	 * that contains $anchor.  When found, splice in an <a> element.
	 *
	 * Skips:
	 *   – Any <a> element (no nested links ever).
	 *   – Any element in $this->excluded_tags.
	 *
	 * @return bool  true if a replacement was made.
	 */
	private function inject_one( DOMElement $root, string $anchor, string $url, DOMDocument $doc ): bool {
		/* Snapshot child nodes so DOM mutations during traversal are safe */
		$children = $this->snapshot( $root );

		foreach ( $children as $node ) {
			/* ── Recurse into allowed elements ───────────────────── */
			if ( $node->nodeType === XML_ELEMENT_NODE ) {
				$tag = strtolower( $node->tagName );

				// Hard-skip <a> and every excluded tag
				if ( $tag === 'a' || in_array( $tag, $this->excluded_tags, true ) ) {
					continue;
				}

				if ( $this->inject_one( $node, $anchor, $url, $doc ) ) {
					return true;
				}
			}

			/* ── Text node: search + splice ──────────────────────── */
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = $node->nodeValue;

				// Case-insensitive, multibyte-safe search
				$pos = mb_stripos( $text, $anchor, 0, 'UTF-8' );
				if ( $pos === false ) {
					continue;
				}

				$len    = mb_strlen( $anchor, 'UTF-8' );
				$before = mb_substr( $text, 0, $pos, 'UTF-8' );
				$match  = mb_substr( $text, $pos, $len, 'UTF-8' );          // original casing
				$after  = mb_substr( $text, $pos + $len, null, 'UTF-8' );

				$parent = $node->parentNode;

				/* Safety: skip if node has no parent (detached or root node) */
				if ( ! $parent ) {
					continue;
				}

				/* Build: [before text] [<a href="…">match</a>] [after text] */
				if ( $before !== '' ) {
					$parent->insertBefore( $doc->createTextNode( $before ), $node );
				}

				$a = $doc->createElement( 'a' );
				$a->setAttribute( 'href', esc_url( $url ) );
				$a->setAttribute( 'class', 'sl-auto-link' );
				// dofollow is the default – we explicitly do NOT set rel="nofollow"
				$a->appendChild( $doc->createTextNode( $match ) );
				$parent->insertBefore( $a, $node );

				if ( $after !== '' ) {
					$parent->insertBefore( $doc->createTextNode( $after ), $node );
				}

				/* Remove the original (now-split) text node */
				$parent->removeChild( $node );

				return true;   // one replacement per link – done
			}
		}

		return false;
	}

	/* ── Helpers ────────────────────────────────────────────────── */

	/**
	 * Static snapshot of a node's children (avoids live-NodeList
	 * mutation issues when we modify the tree during traversal).
	 *
	 * @return DOMNode[]
	 */
	private function snapshot( DOMNode $node ): array {
		$out = [];
		foreach ( $node->childNodes as $child ) {
			$out[] = $child;
		}
		return $out;
	}

	/**
	 * Serialize the *inner* HTML of a DOMElement (the element's own
	 * opening/closing tags are excluded).
	 *
	 * @return string
	 */
	private function inner_html( DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}
		return $html;
	}
}
