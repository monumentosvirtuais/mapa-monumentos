<?php
/**
 * Páginas Monumentos e Artistas: troca a lista escrita à mão (cards fixos) pela lista automática do plugin
 * ([mv3_monuments_list] / [mv3_artists_list]), para que obras e perfis novos apareçam sozinhos.
 * Uso:  wp eval-file mapa-wifi/tools/listas-dinamicas.php          (só confere e compara)
 *       wp eval-file mapa-wifi/tools/listas-dinamicas.php aplicar  (troca; a versão anterior fica nas revisões da página)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$aplicar = isset( $args[0] ) && 'aplicar' === $args[0];

/** Acha o <div class="...$classe..."> ... </div> de fora a fora (contando divs internas). */
function mvl_bloco( $html, $classe ) {
	if ( ! preg_match( '/<div\b[^>]*class="[^"]*\b' . preg_quote( $classe, '/' ) . '\b[^"]*"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE ) ) { return null; }
	$ini = $m[0][1]; $pos = $ini + strlen( $m[0][0] ); $nivel = 1;
	while ( $nivel > 0 && preg_match( '/<div\b|<\/div>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
		$nivel += ( '</div>' === strtolower( $t[0][0] ) ) ? -1 : 1;
		$pos = $t[0][1] + strlen( $t[0][0] );
	}
	return $nivel ? null : array( $ini, $pos - $ini );
}

$casos = array(
	'monumentos' => array( 'sc' => '[mv3_monuments_list]', 'card' => '/<a\b[^>]*\/monumento\//', 'tipo' => 'mv3_monument' ),
	'artistas'   => array( 'sc' => '[mv3_artists_list]', 'card' => '/<a\b[^>]*\/artista\//', 'tipo' => 'mv3_artist' ),
);
foreach ( $casos as $slug => $c ) {
	WP_CLI::log( "\n=== Página /$slug/ ===" );
	$pg = get_page_by_path( $slug );
	if ( ! $pg ) { WP_CLI::log( 'Não é uma página (pode ser montada pelo plugin). Nada a fazer.' ); continue; }
	$html = $pg->post_content;
	if ( false !== strpos( $html, $c['sc'] ) ) { WP_CLI::log( "Já usa a lista automática {$c['sc']}. Nada a fazer." ); continue; }
	$bloco = mvl_bloco( $html, 'mv3-grid' );
	if ( ! $bloco ) { WP_CLI::log( 'Não achei uma lista de cards (mv3-grid). Nada a fazer.' ); continue; }
	$fixo  = substr( $html, $bloco[0], $bloco[1] );
	$n_fix = preg_match_all( $c['card'], $fixo );
	$auto  = do_shortcode( $c['sc'] );
	$n_aut = preg_match_all( $c['card'], $auto );
	$pub   = (int) wp_count_posts( $c['tipo'] )->publish;
	WP_CLI::log( "Lista escrita à mão: $n_fix cards · lista automática do plugin: $n_aut cards · publicados no site: $pub" );
	$cl_fix = preg_match( '/class="([^"]*mv3-card[^"]*)"/', $fixo, $a ) ? $a[1] : '?';
	$cl_aut = preg_match( '/class="([^"]*mv3-card[^"]*)"/', $auto, $b ) ? $b[1] : '?';
	WP_CLI::log( "Visual: cards fixos usam class=\"$cl_fix\" · automáticos usam class=\"$cl_aut\"" );
	if ( $n_aut < $n_fix || $n_aut < 1 ) { WP_CLI::warning( 'A lista automática mostra menos itens que a atual. Não vou trocar.' ); continue; }
	if ( ! $aplicar ) { WP_CLI::log( 'Pronto para trocar.' ); continue; }
	$sc   = "\n<!-- wp:shortcode -->\n{$c['sc']}\n<!-- /wp:shortcode -->\n";
	// se a lista estava dentro de um bloco HTML do editor, troca o bloco inteiro
	$antes = substr( $html, 0, $bloco[0] ); $depois = substr( $html, $bloco[0] + $bloco[1] );
	if ( preg_match( '/<!-- wp:html -->\s*$/', $antes ) && preg_match( '/^\s*<!-- \/wp:html -->/', $depois ) ) {
		$antes  = preg_replace( '/<!-- wp:html -->\s*$/', '', $antes );
		$depois = preg_replace( '/^\s*<!-- \/wp:html -->/', '', $depois );
	}
	kses_remove_filters();
	wp_update_post( wp_slash( array( 'ID' => $pg->ID, 'post_content' => $antes . $sc . $depois ) ) );
	WP_CLI::success( "/$slug/ agora usa {$c['sc']} (a versão anterior está nas revisões da página)." );
}
if ( ! $aplicar ) { WP_CLI::success( 'Conferência concluída. Nada foi alterado. Para trocar, rode de novo com: aplicar' ); }
