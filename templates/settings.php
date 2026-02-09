<?php
/**
 * Admin template – SemanticLinker AI → Ustawienia
 *
 * Loaded by SL_Settings::render_settings().  Has access to all
 * WordPress functions and the SL_* classes via the autoloader.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s        = SL_Settings::all();                                          // merged settings
$last_run = get_option( 'sl_last_indexing_run', '' );
$all_pts  = get_post_types( [ 'public' => true ], 'objects' );

// Decrypt API key for display in form (encrypted in DB)
$api_key_decrypted = SL_Settings::get_api_key();
?>
<div class="wrap sl-wrap">

	<!-- Page title ─────────────────────────────────────────────── -->
	<h1 class="sl-page-title">
		<span class="dashicons dashicons-admin-links"></span>
		SemanticLinker AI &#8212; Ustawienia
	</h1>

	<!-- Success notice (set via transient after PRG redirect) ──── -->
	<?php
	if ( get_transient( 'sl_settings_saved' ) ) {
		delete_transient( 'sl_settings_saved' );
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong>Ustawienia zapisane.</strong></p>
			<button type="button" class="notice-dismiss">
				<span class="screen-reader-text">Zamknij</span>
			</button>
		</div>
	<?php } ?>

	<!-- Status bar ─────────────────────────────────────────────── -->
	<div class="sl-status-bar">
		<span class="sl-status-item">
			API Key:
			<?php echo $api_key_decrypted
				? '<span class="sl-badge sl-badge-ok">Skonfigurowana</span>'
				: '<span class="sl-badge sl-badge-warn">Brak</span>';
			?>
		</span>
		<span class="sl-status-item" title="Plugin obsługuje tylko język polski">
			<span class="sl-flag-pl" style="display: inline-block; width: 16px; height: 11px; background: linear-gradient(to bottom, #fff 50%, #dc143c 50%); border: 1px solid #ccc; border-radius: 2px; vertical-align: middle; margin-right: 4px;"></span>
			<span style="font-size: 12px; color: #666;">Tylko polski</span>
		</span>
		<?php if ( $last_run ) : ?>
			<span class="sl-status-item">
				Ostatnia indeksacja:
				<strong><?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $last_run ) ) ); ?></strong>
			</span>
		<?php endif; ?>
	</div>

	<!-- Two-column layout: main content + sidebar -->
	<div class="sl-layout">
		<div class="sl-main">

	<!-- ══════════════════════════════════════════════════════════
	     FORM – saved via admin_init PRG (see SL_Settings)
	     ══════════════════════════════════════════════════════════ -->
	<form method="post" class="sl-form">
		<?php wp_nonce_field( 'sl_settings_save', 'sl_nonce' ); ?>

		<!-- ── API Konfiguracja ──────────────────────────────── -->
		<div class="sl-card">
			<h2 class="sl-card-title">API Konfiguracja</h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="sl_api_key">Google Gemini API Key</label></th>
					<td>
						<input
							type="password"
							id="sl_api_key"
							name="api_key"
							value="<?php echo esc_attr( $api_key_decrypted ); ?>"
							class="regular-text"
							autocomplete="off"
							placeholder="AIza…"
						/>
						<p class="description">
							Wymagany do generowania embeddingów przez Google Gemini API.<br>
							Klucz uzyskasz w <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.<br>
							<span style="color: #46b450;">&#128274; Klucz jest szyfrowany w bazie danych.</span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_model">Model embeddingów</label></th>
					<td>
						<input
							type="text"
							id="sl_model"
							name="embedding_model"
							value="<?php echo esc_attr( $s['embedding_model'] ); ?>"
							class="regular-text"
							placeholder="gemini-embedding-001"
						/>
						<p class="description">
							Model embeddingów Google Gemini. Domyślnie: <code>gemini-embedding-001</code>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_filter_model">Model filtra AI</label></th>
					<td>
						<input
							type="text"
							id="sl_filter_model"
							name="filter_model"
							value="<?php echo esc_attr( $s['filter_model'] ?? 'gemini-2.5-flash' ); ?>"
							class="regular-text"
							placeholder="gemini-2.5-flash"
						/>
						<p class="description">
							Model Gemini do walidacji kontekstowej anchorów. Domyślnie: <code>gemini-2.5-flash</code>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Parametry Linkowania ──────────────────────────── -->
		<div class="sl-card">
			<h2 class="sl-card-title">Parametry Linkowania</h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="sl_threshold">Próg podobieństwa</label></th>
					<td>
						<input
							type="range"
							id="sl_threshold"
							name="similarity_threshold"
							min="0.50"
							max="1.00"
							step="0.01"
							value="<?php echo esc_attr( $s['similarity_threshold'] ); ?>"
							class="sl-range"
							oninput="document.getElementById('sl_threshold_display').textContent=this.value"
						/>
						<span id="sl_threshold_display" class="sl-range-val">
							<?php echo esc_html( $s['similarity_threshold'] ); ?>
						</span>
						<p class="description">
							Minimalne <em>cosine similarity</em> (0.50 – 1.00), żeby link został
							zaproponowany. Im mniejszy współczynnik tym więcej linków.<br>
							<strong>0.75</strong> &#8212; zalecany punkt startowy. Im wyższe, tym
							mniej propozycji, ale bardziej pewnych.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_max_links">Max linków / post</label></th>
					<td>
						<input
							type="number"
							id="sl_max_links"
							name="max_links_per_post"
							value="<?php echo esc_attr( $s['max_links_per_post'] ); ?>"
							min="1"
							max="30"
							class="small-text"
						/>
						<p class="description">
							Maksymalna liczba automatycznych linków w jednym artykule (1–30).<br>
							<strong>Zalecane:</strong> 10 linków dla standardowych artykułów.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_cluster_threshold">Próg klastrowania anchorów</label></th>
					<td>
						<input
							type="range"
							id="sl_cluster_threshold"
							name="cluster_threshold"
							min="0.50"
							max="0.99"
							step="0.01"
							value="<?php echo esc_attr( $s['cluster_threshold'] ); ?>"
							class="sl-range"
							oninput="document.getElementById('sl_cluster_display').textContent=this.value"
						/>
						<span id="sl_cluster_display" class="sl-range-val">
							<?php echo esc_html( $s['cluster_threshold'] ); ?>
						</span>
						<p class="description">
							Próg podobieństwa semantycznego dla grupowania anchorów (0.50 – 0.99).<br>
							Anchory o podobieństwie powyżej tego progu są traktowane jako ten sam klaster
							(np. "kredyt hipoteczny" ≈ "kredytu hipotecznego").<br>
							<strong>0.75</strong> &#8212; zalecany dla języka polskiego. Niższe = więcej anchorów w jednym klastrze.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_min_anchor_words">Długość anchor text</label></th>
					<td>
						<label>
							Min:
							<input
								type="number"
								id="sl_min_anchor_words"
								name="min_anchor_words"
								value="<?php echo esc_attr( $s['min_anchor_words'] ); ?>"
								min="1"
								max="10"
								class="small-text"
							/>
						</label>
						&nbsp;&nbsp;
						<label>
							Max:
							<input
								type="number"
								id="sl_max_anchor_words"
								name="max_anchor_words"
								value="<?php echo esc_attr( $s['max_anchor_words'] ); ?>"
								min="1"
								max="15"
								class="small-text"
							/>
						</label>
						<span style="margin-left: 10px; color: #666;">wyrazów</span>
						<p class="description">
							Minimalna i maksymalna liczba wyrazów w anchor text (tekście linku).<br>
							Znaki interpunkcyjne nie są liczone jako wyrazy.<br>
							<strong>Zalecane:</strong> 3–10 wyrazów dla naturalnie wyglądających linków.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Linkowanie w kategorii</th>
					<td>
						<label class="sl-cb-label">
							<input
								type="checkbox"
								name="same_category_only"
								value="1"
								<?php checked( $s['same_category_only'] ); ?>
							/>
							Linkuj tylko posty z tej samej kategorii
						</label>
						<p class="description">
							Gdy włączone, linki będą tworzone tylko między postami,
							które mają co najmniej jedną wspólną kategorię.<br>
							Zwiększa trafność kontekstową linków.
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Filtr AI Gemini ──────────────────────────────── -->
		<div class="sl-card">
			<h2 class="sl-card-title">Filtr AI Gemini</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Walidacja anchor-tytuł</th>
					<td>
						<label class="sl-cb-label">
							<input
								type="checkbox"
								name="gemini_anchor_filter"
								value="1"
								<?php checked( $s['gemini_anchor_filter'] ); ?>
							/>
							Włącz dodatkowy filtr Gemini
						</label>
						<p class="description">
							Gdy włączony, każdy proponowany link jest dodatkowo weryfikowany przez Gemini AI.<br>
							Gemini ocenia czy <strong>anchor text</strong> pasuje kontekstowo do
							<strong>tytułu artykułu docelowego</strong>.<br>
							<span style="color: #d63638;"><strong>Uwaga:</strong> Zwiększa zużycie API i czas przetwarzania.</span>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Post Types ────────────────────────────────────── -->
		<div class="sl-card">
			<h2 class="sl-card-title">Post Types</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Uwzględniane typy</th>
					<td>
						<div class="sl-checkbox-grid">
							<?php foreach ( $all_pts as $pt ) : ?>
								<label class="sl-cb-label">
									<input
										type="checkbox"
										name="post_types[]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $s['post_types'], true ) ); ?>
									/>
									<?php echo esc_html( $pt->labels->singular_name ); ?>
									<code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description">
							Zaznaczone typy będą zarówno <strong>źródłem</strong> jak i
							<strong>celem</strong> linkowania.
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Wykluczenia ───────────────────────────────────── -->
		<div class="sl-card">
			<h2 class="sl-card-title">Wykluczenia (Safety Guardrails)</h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="sl_excluded_tags">Tagi wykluczane</label></th>
					<td>
						<textarea
							id="sl_excluded_tags"
							name="excluded_tags"
							rows="4"
							cols="18"
							class="small-text"
						><?php echo esc_textarea( implode( "\n", $s['excluded_tags'] ) ); ?></textarea>
						<p class="description">
							Po jednym tagu na linię, <strong>bez</strong> &lt; &gt;<br>
							Linki nigdy nie zostaną wstawione wewnątrz tych tagów ani
							wewnątrz istniejących <code>&lt;a&gt;</code>.<br>
							<code>script</code> i <code>style</code> są zawsze dołączone
							automatycznie.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sl_excluded_post_ids">Wykluczone posty (ID)</label></th>
					<td>
						<textarea
							id="sl_excluded_post_ids"
							name="excluded_post_ids"
							rows="3"
							cols="30"
							class="small-text"
							placeholder="123, 456, 789"
						><?php echo esc_textarea( implode( ', ', $s['excluded_post_ids'] ) ); ?></textarea>
						<p class="description">
							ID postów do wykluczenia z linkowania (jako źródło i cel).<br>
							Podaj ID oddzielone przecinkami lub w osobnych liniach.<br>
							Przykład: <code>123, 456, 789</code>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Submit ─────────────────────────────────────────────── -->
		<input type="hidden" name="sl_save" value="1" />
		<?php submit_button( 'Zapisz ustawienia', 'primary', 'submit', false ); ?>
	</form>

		</div><!-- /.sl-main -->

		<!-- ══════════════════════════════════════════════════════════
		     SIDEBAR – Zarządzanie indeksacją
		     ══════════════════════════════════════════════════════════ -->
		<div class="sl-sidebar">

	<div class="sl-card">
		<h2 class="sl-card-title">Zarządzanie indeksacją linków</h2>

		<!-- Auto-cron toggle (inside form context) -->
		<form method="post" style="margin-bottom: 15px;">
			<?php wp_nonce_field( 'sl_settings_save', 'sl_nonce' ); ?>
			<input type="hidden" name="sl_save" value="1" />
			<!-- Preserve other settings by including them as hidden fields -->
			<input type="hidden" name="api_key" value="<?php echo esc_attr( $api_key_decrypted ); ?>" />
			<input type="hidden" name="embedding_model" value="<?php echo esc_attr( $s['embedding_model'] ); ?>" />
			<input type="hidden" name="similarity_threshold" value="<?php echo esc_attr( $s['similarity_threshold'] ); ?>" />
			<input type="hidden" name="max_links_per_post" value="<?php echo esc_attr( $s['max_links_per_post'] ); ?>" />
			<input type="hidden" name="min_anchor_words" value="<?php echo esc_attr( $s['min_anchor_words'] ); ?>" />
			<input type="hidden" name="max_anchor_words" value="<?php echo esc_attr( $s['max_anchor_words'] ); ?>" />
			<input type="hidden" name="cluster_threshold" value="<?php echo esc_attr( $s['cluster_threshold'] ); ?>" />
			<input type="hidden" name="excluded_tags" value="<?php echo esc_attr( implode( "\n", $s['excluded_tags'] ) ); ?>" />
			<input type="hidden" name="excluded_post_ids" value="<?php echo esc_attr( implode( ', ', $s['excluded_post_ids'] ) ); ?>" />
			<?php if ( $s['same_category_only'] ) : ?>
				<input type="hidden" name="same_category_only" value="1" />
			<?php endif; ?>
			<?php if ( $s['gemini_anchor_filter'] ) : ?>
				<input type="hidden" name="gemini_anchor_filter" value="1" />
			<?php endif; ?>
			<?php foreach ( $s['post_types'] as $pt ) : ?>
				<input type="hidden" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>" />
			<?php endforeach; ?>

			<label class="sl-cb-label" style="display: flex; align-items: center; gap: 8px;">
				<input
					type="checkbox"
					name="cron_enabled"
					value="1"
					<?php checked( $s['cron_enabled'] ); ?>
					onchange="this.form.submit();"
				/>
				<span>Włącz automatyczną indeksację (co godzinę)</span>
			</label>
		</form>

		<p class="description" style="margin-bottom: 12px;">
			<?php if ( $s['cron_enabled'] ) : ?>
				<span style="color: #46b450;">✓ Cron aktywny</span> – indeksacja uruchamia się automatycznie co godzinę.
			<?php else : ?>
				<span style="color: #d63638;">✗ Cron wyłączony</span> – użyj przycisku poniżej, aby uruchomić indeksację ręcznie.
			<?php endif; ?>
		</p>

		<div style="display: flex; flex-direction: column; gap: 10px;">
			<button id="sl-btn-reindex" class="button button-primary" type="button" style="width: 100%;">
				&#8635; Reindeksuj teraz
			</button>
			<button id="sl-btn-cancel" class="button" type="button" style="width: 100%; display: none; color: #d63638; border-color: #d63638;">
				✕ Anuluj proces
			</button>
			<button id="sl-btn-delete-all" class="button" type="button" style="width: 100%; color: #a00; border-color: #a00;">
				🗑️ Usuń wszystkie linki
			</button>
		</div>

		<div id="sl-reindex-status" style="margin-top: 10px; font-size: 13px; color: #666;"></div>

		<!-- Progress bars for each phase -->
		<div id="sl-progress-wrap" style="display: none; margin-top: 15px;">
			<!-- Indexing progress -->
			<div class="sl-progress-section" id="sl-progress-indexing" style="margin-bottom: 12px;">
				<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
					<span style="font-size: 12px; font-weight: 600; color: #1e3a5f;">📄 Indeksowanie</span>
					<span id="sl-progress-indexing-percent" style="font-size: 11px; color: #666;">0%</span>
				</div>
				<div style="background: #e0e0e0; border-radius: 4px; height: 14px; overflow: hidden;">
					<div id="sl-progress-indexing-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
				</div>
				<p id="sl-progress-indexing-text" style="margin: 3px 0 0; font-size: 11px; color: #666;">Oczekiwanie...</p>
			</div>

			<!-- Matching progress -->
			<div class="sl-progress-section" id="sl-progress-matching" style="margin-bottom: 12px; opacity: 0.5;">
				<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
					<span style="font-size: 12px; font-weight: 600; color: #1e3a5f;">🔗 Dopasowywanie</span>
					<span id="sl-progress-matching-percent" style="font-size: 11px; color: #666;">0%</span>
				</div>
				<div style="background: #e0e0e0; border-radius: 4px; height: 14px; overflow: hidden;">
					<div id="sl-progress-matching-bar" style="background: #28a745; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
				</div>
				<p id="sl-progress-matching-text" style="margin: 3px 0 0; font-size: 11px; color: #666;">Oczekiwanie...</p>
			</div>

			<!-- AI Filtering progress (optional - shown only when enabled) -->
			<div class="sl-progress-section" id="sl-progress-filtering" style="display: none; opacity: 0.5;">
				<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
					<span style="font-size: 12px; font-weight: 600; color: #1e3a5f;">🤖 Filtrowanie AI</span>
					<span id="sl-progress-filtering-percent" style="font-size: 11px; color: #666;">0%</span>
				</div>
				<div style="background: #e0e0e0; border-radius: 4px; height: 14px; overflow: hidden;">
					<div id="sl-progress-filtering-bar" style="background: #fd7e14; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
				</div>
				<p id="sl-progress-filtering-text" style="margin: 3px 0 0; font-size: 11px; color: #666;">Oczekiwanie...</p>
			</div>
		</div>
	</div>

	<!-- Info box -->
	<div class="sl-card" style="background: #fff8e6; border-color: #f0c36d;">
		<h2 class="sl-card-title" style="color: #856404; border-bottom-color: #f0c36d;">⚠️ Ważne</h2>
		<p style="font-size: 13px; color: #664d03; margin: 0; line-height: 1.5;">
			Reindeksacja działa <strong>przyrostowo</strong> – przetwarza tylko nowe posty lub te ze zmienioną treścią.
		</p>
		<p style="font-size: 13px; color: #664d03; margin: 10px 0 0; line-height: 1.5;">
			<strong>Aby zastosować nowe ustawienia</strong>, najpierw usuń wszystkie linki, a następnie uruchom reindeksację.
		</p>
	</div>

		</div><!-- /.sl-sidebar -->
	</div><!-- /.sl-layout -->

	<!-- ══════════════════════════════════════════════════════════
	     DEBUG LOGS (full width, outside the two-column layout)
	     ══════════════════════════════════════════════════════════ -->
	<div class="sl-card">
		<h2 class="sl-card-title">Debug Logs</h2>
		<p class="description" style="margin-bottom: 12px;">
			Ostatnie 200 wpisów logów. Użyj przycisków do filtrowania i kopiowania.
		</p>

		<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
			<button id="sl-btn-refresh-logs" class="button" type="button">🔄 Odśwież logi</button>
			<button id="sl-btn-copy-logs" class="button" type="button">📋 Kopiuj do schowka</button>
			<button id="sl-btn-clear-logs" class="button" type="button" style="color: #a00;">🗑️ Wyczyść logi</button>
			<label style="margin-left: 15px;">
				Filtr kontekstu:
				<select id="sl-log-filter">
					<option value="">Wszystkie</option>
					<option value="matcher">matcher</option>
					<option value="indexer">indexer</option>
					<option value="api">api</option>
				</select>
			</label>
			<label>
				Szukaj:
				<input type="text" id="sl-log-search" placeholder="np. cluster, anchor..." style="width: 150px;" />
			</label>
		</div>

		<div id="sl-debug-logs" style="max-height: 500px; overflow: auto; border: 1px solid #ccc; background: #f9f9f9; padding: 10px; font-family: monospace; font-size: 11px;">
			<?php
			$logs = SL_Debug::get_logs();
			if ( empty( $logs ) ) :
				echo '<p style="color: #666;">Brak logów.</p>';
			else :
				// Show newest first
				$logs = array_reverse( $logs );
				?>
				<table style="width: 100%; border-collapse: collapse;">
					<thead>
						<tr style="background: #e0e0e0; position: sticky; top: 0;">
							<th style="padding: 4px 8px; text-align: left; width: 140px;">Czas</th>
							<th style="padding: 4px 8px; text-align: left; width: 80px;">Kontekst</th>
							<th style="padding: 4px 8px; text-align: left;">Wiadomość</th>
							<th style="padding: 4px 8px; text-align: left;">Dane</th>
						</tr>
					</thead>
					<tbody id="sl-logs-tbody">
						<?php foreach ( $logs as $log ) : ?>
							<tr class="sl-log-row"
								data-context="<?php echo esc_attr( $log['context'] ?? '' ); ?>"
								data-searchable="<?php echo esc_attr( strtolower( $log['message'] . ' ' . json_encode( $log['data'] ?? [] ) ) ); ?>"
								style="border-bottom: 1px solid #ddd; vertical-align: top;">
								<td style="padding: 4px 8px; white-space: nowrap; color: #666;">
									<?php echo esc_html( $log['time'] ?? '' ); ?>
								</td>
								<td style="padding: 4px 8px;">
									<span style="background: <?php
										$ctx_colors = [
											'matcher' => '#d4edda',
											'indexer' => '#cce5ff',
											'api'     => '#fff3cd',
										];
										echo $ctx_colors[ $log['context'] ] ?? '#e2e3e5';
									?>; padding: 2px 6px; border-radius: 3px; font-size: 10px;">
										<?php echo esc_html( $log['context'] ?? '' ); ?>
									</span>
								</td>
								<td style="padding: 4px 8px; word-break: break-word;">
									<?php echo esc_html( $log['message'] ?? '' ); ?>
								</td>
								<td style="padding: 4px 8px; word-break: break-all; max-width: 400px;">
									<?php
									if ( ! empty( $log['data'] ) ) {
										// Pretty print JSON for readability
										$json = json_encode( $log['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
										echo '<pre style="margin: 0; white-space: pre-wrap; font-size: 10px; max-height: 150px; overflow: auto;">';
										echo esc_html( $json );
										echo '</pre>';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

</div><!-- .wrap -->
