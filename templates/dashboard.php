<?php
/**
 * Admin template – SemanticLinker AI → Active Links (dashboard)
 *
 * Loaded by SL_Dashboard::render().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all_links = SL_DB::get_all_links();   // all statuses, newest first

/**
 * Group links by target URL (cluster view).
 * All links pointing to the same URL form one cluster.
 * The shortest anchor text is used as the cluster representative.
 *
 * Returns array: [ 'target_url' => [ 'display_anchor' => '...', 'all_anchors' => [...], 'links' => [...] ] ]
 */
function sl_group_links_by_target_url( array $links ): array {
	$clusters = [];

	foreach ( $links as $link ) {
		$url = $link->target_url;

		if ( ! isset( $clusters[ $url ] ) ) {
			$clusters[ $url ] = [
				'display_anchor' => $link->anchor_text,
				'all_anchors'    => [],
				'links'          => [],
			];
		}

		$clusters[ $url ]['links'][] = $link;

		// Collect unique anchors for this cluster
		$anchor_lower = mb_strtolower( trim( $link->anchor_text ), 'UTF-8' );
		if ( ! isset( $clusters[ $url ]['all_anchors'][ $anchor_lower ] ) ) {
			$clusters[ $url ]['all_anchors'][ $anchor_lower ] = $link->anchor_text;
		}

		// Use the shortest anchor as the display anchor (cluster representative)
		$current_len = mb_strlen( $clusters[ $url ]['display_anchor'], 'UTF-8' );
		$new_len     = mb_strlen( $link->anchor_text, 'UTF-8' );
		if ( $new_len < $current_len ) {
			$clusters[ $url ]['display_anchor'] = $link->anchor_text;
		}
	}

	// Sort clusters by display anchor
	uasort( $clusters, function( $a, $b ) {
		return strcmp(
			mb_strtolower( $a['display_anchor'], 'UTF-8' ),
			mb_strtolower( $b['display_anchor'], 'UTF-8' )
		);
	} );

	return $clusters;
}

$link_clusters = sl_group_links_by_target_url( $all_links );
?>
<div class="wrap sl-wrap">

	<!-- Page title ──────────────────────────────────────────────── -->
	<h1 class="sl-page-title">
		<span class="dashicons dashicons-admin-links"></span>
		SemanticLinker AI &#8212; Active Links
	</h1>

	<?php if ( empty( $all_links ) ) : ?>
		<!-- Empty state ─────────────────────────────────────────── -->
		<div class="sl-empty">
			<p>
				Żadnych linków jeszcze nie wygenerowano.<br>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=semanticlinker' ) ); ?>">
					Sprawdź ustawienia
				</a>
				i uruchomij indeksację (przycisk "Reindeksuj teraz" na stronie ustawień).
			</p>
		</div>

	<?php else : ?>

		<!-- Stats strip ───────────────────────────────────────── -->
		<?php
		$active_count   = 0;
		$rejected_count = 0;
		$filtered_count = 0;
		foreach ( $all_links as $l ) {
			if ( $l->status === 'active' )   $active_count++;
			if ( $l->status === 'rejected' ) $rejected_count++;
			if ( $l->status === 'filtered' ) $filtered_count++;
		}
		$cluster_count = count( $link_clusters );
		?>
		<div class="sl-stats">
			<span class="sl-badge sl-badge-ok">
				<?php echo esc_html( $active_count ); ?> aktywn<?php echo $active_count === 1 ? 'y' : 'ych'; ?>
			</span>
			<span class="sl-badge sl-badge-warn">
				<?php echo esc_html( $rejected_count ); ?> odrzucon<?php echo $rejected_count === 1 ? 'y' : 'ych'; ?>
			</span>
			<?php if ( $filtered_count > 0 ) : ?>
				<span class="sl-badge sl-badge-filtered">
					<?php echo esc_html( $filtered_count ); ?> wyfiltrowan<?php echo $filtered_count === 1 ? 'y' : 'ych'; ?>
				</span>
			<?php endif; ?>
			<span class="sl-badge sl-badge-cluster">
				<?php echo esc_html( $cluster_count ); ?> klastr<?php echo $cluster_count === 1 ? '' : ( $cluster_count >= 2 && $cluster_count <= 4 ? 'y' : 'ów' ); ?>
			</span>
			<label class="sl-toggle-rejected">
				<input type="checkbox" id="sl-show-rejected" checked />
				Pokaż odrzucone
			</label>
			<?php if ( $filtered_count > 0 ) : ?>
				<label class="sl-toggle-rejected" style="margin-left: 10px;">
					<input type="checkbox" id="sl-show-filtered" checked />
					Pokaż wyfiltrowane (AI)
				</label>
			<?php endif; ?>
			<button id="sl-btn-delete-all" class="button button-link-delete" type="button" style="margin-left: 15px; color: #a00;">
				Usuń wszystkie linki
			</button>
		</div>

		<!-- Links table (grouped by anchor clusters) ─────────────── -->
		<table class="widefat sl-links-table sl-show-rejected sl-show-filtered" id="sl-links-table">
			<thead>
				<tr>
					<th class="sl-col-source">Artykuł źródłowy</th>
					<th class="sl-col-anchor">Anchor (fraza)</th>
					<th class="sl-col-target">URL docelowy</th>
					<th class="sl-col-score">Score</th>
					<th class="sl-col-status">Status</th>
					<th class="sl-col-action">Akcje</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$cluster_index = 0;
				foreach ( $link_clusters as $target_url => $cluster ) :
					$cluster_index++;
					$cluster_links  = $cluster['links'];
					$cluster_size   = count( $cluster_links );
					$unique_anchors = array_values( $cluster['all_anchors'] );
					$is_even_cluster = ( $cluster_index % 2 === 0 );
				?>
					<!-- Cluster header row -->
					<tr class="sl-cluster-header <?php echo $is_even_cluster ? 'sl-cluster-even' : 'sl-cluster-odd'; ?>">
						<td colspan="6">
							<div class="sl-cluster-info">
								<span class="sl-cluster-label">KLASTER</span>
								<span class="sl-cluster-anchor">
									<?php
									// Extract slug from URL and truncate to 5 words
									$slug = trim( wp_parse_url( $target_url, PHP_URL_PATH ), '/' );
									$slug = preg_replace( '/[-_\/]+/', ' ', $slug ); // Convert dashes/underscores/slashes to spaces
									$words = preg_split( '/\s+/', $slug );
									// Filter out purely numeric words (dates like 2026, 02, 06)
									$words = array_values( array_filter( $words, function( $w ) {
										return ! preg_match( '/^\d+$/', $w );
									} ) );
									if ( count( $words ) > 5 ) {
										$slug = implode( ' ', array_slice( $words, 0, 5 ) ) . '…';
									} else {
										$slug = implode( ' ', $words );
									}
									?>
									<a href="<?php echo esc_url( $target_url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $target_url ); ?>">
										<code><?php echo esc_html( $slug ); ?></code>
									</a>
								</span>
								<span class="sl-cluster-count">
									<?php
									echo esc_html( $cluster_size );
									if ( $cluster_size === 1 ) {
										echo ' link';
									} elseif ( $cluster_size >= 2 && $cluster_size <= 4 ) {
										echo ' linki';
									} else {
										echo ' linków';
									}
									?>
								</span>
							</div>
						</td>
					</tr>

					<?php foreach ( $cluster_links as $link ) :
						$is_rejected = ( $link->status === 'rejected' );
						$is_filtered = ( $link->status === 'filtered' );
						$pct         = round( (float) $link->similarity_score * 100, 1 );
						$row_class   = $is_even_cluster ? 'sl-cluster-even' : 'sl-cluster-odd';
						if ( $is_rejected ) $row_class .= ' sl-row-rejected';
						if ( $is_filtered ) $row_class .= ' sl-row-filtered';
					?>
						<tr class="sl-row <?php echo esc_attr( $row_class ); ?>"
							data-link-id="<?php echo esc_attr( $link->ID ); ?>"
							data-status="<?php echo esc_attr( $link->status ); ?>">

							<!-- Source post title (linked to frontend URL) -->
							<td>
								<?php $source_url = get_permalink( $link->post_id ); ?>
								<a href="<?php echo esc_url( $source_url ); ?>"
								   target="_blank" rel="noopener"
								   title="<?php echo esc_attr( $source_url ); ?>"
								   style="max-width: 250px; display: inline-block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
									<?php echo esc_html( $link->source_title ); ?>
								</a>
							</td>

							<!-- Anchor text -->
							<td>
								<code class="sl-anchor">
									<?php echo esc_html( $link->anchor_text ); ?>
								</code>
							</td>

							<!-- Target URL (opens in new tab) -->
							<td>
								<a href="<?php echo esc_url( $link->target_url ); ?>"
								   target="_blank" rel="noopener">
									<?php echo esc_html( $link->target_url ); ?>
								</a>
							</td>

							<!-- Similarity score bar + percentage -->
							<td class="sl-col-score">
								<div class="sl-bar">
									<div class="sl-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
								</div>
								<span class="sl-score-num">
									<?php echo esc_html( $pct ); ?>%
								</span>
							</td>

							<!-- Status badge -->
							<td>
								<?php if ( $is_filtered ) : ?>
									<span class="sl-badge sl-badge-filtered">Wyfiltrowany (AI)</span>
								<?php elseif ( $is_rejected ) : ?>
									<span class="sl-badge sl-badge-warn">Odrzucony</span>
								<?php else : ?>
									<span class="sl-badge sl-badge-ok">Aktywny</span>
								<?php endif; ?>
							</td>

							<!-- Action buttons -->
							<td>
								<?php if ( $link->status === 'active' ) : ?>
									<button
										class="button button-link sl-btn-reject"
										data-link-id="<?php echo esc_attr( $link->ID ); ?>"
									>
										Odrzuć
									</button>
								<?php else : ?>
									<button
										class="button button-link sl-btn-restore"
										data-link-id="<?php echo esc_attr( $link->ID ); ?>"
										style="color: #0073aa;"
									>
										Przywróć
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>

</div><!-- .wrap -->
