<?php
/**
 * QR arte dos monumentos. Rode na pasta public_html:
 *   wp eval-file mapa-wifi/tools/qr.php gerar 471 424     → gera (ou refaz) o QR desses monumentos
 *   wp eval-file mapa-wifi/tools/qr.php comunidade        → gera para todos os monumentos inscritos pelo mapa
 *   wp eval-file mapa-wifi/tools/qr.php ver 471           → mostra o que está guardado
 */
$args = isset( $args ) ? $args : array();
$acao = array_shift( $args );
if ( 'comunidade' === $acao ) {
	$args = get_posts( array( 'post_type' => 'mv3_monument', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_mve_comunidade', 'meta_value' => '1' ) );
	$acao = 'gerar';
}
$ids = array_filter( array_map( 'intval', $args ) );
if ( ! in_array( $acao, array( 'gerar', 'ver' ), true ) || ! $ids ) { WP_CLI::error( 'Use: gerar|ver seguido dos números (ID), ou: comunidade' ); }
foreach ( $ids as $id ) {
	if ( 'mv3_monument' !== get_post_type( $id ) ) { WP_CLI::warning( "$id não é um monumento." ); continue; }
	$t = microtime( true );
	$q = 'gerar' === $acao ? mve_qr_gerar( $id, true ) : get_post_meta( $id, '_mve_qr', true );
	if ( ! is_array( $q ) ) { WP_CLI::warning( "#$id " . get_the_title( $id ) . ': sem QR' . ( 'gerar' === $acao ? ' (veja o registro de erros)' : '' ) ); continue; }
	WP_CLI::success( sprintf( '#%d %s · %s · %s KB · %s%s', $id, get_the_title( $id ), 'foto' === $q['modelo'] ? 'QR com a foto' : 'QR desenhado', round( filesize( $q['arquivo'] ) / 1024 ), $q['url'], 'gerar' === $acao ? sprintf( ' (%.1fs)', microtime( true ) - $t ) : '' ) );
	WP_CLI::log( '   abre: ' . $q['link'] . ' · impressão: ' . $q['url_impressao'] );
}
