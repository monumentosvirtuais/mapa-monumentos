<?php
/**
 * QR arte dos monumentos. Rode na pasta public_html:
 *   wp eval-file mapa-wifi/tools/qr.php gerar 471 424     → gera (ou refaz) o QR desses monumentos
 *   wp eval-file mapa-wifi/tools/qr.php comunidade        → gera para todos os monumentos inscritos pelo mapa
 *   wp eval-file mapa-wifi/tools/qr.php foto 424 3        → prefere a 3ª foto da galeria para o QR (e refaz)
 *   wp eval-file mapa-wifi/tools/qr.php ver 471           → mostra o que está guardado
 */
$args = isset( $args ) ? $args : array();
$acao = array_shift( $args );
if ( 'foto' === $acao ) {
	$mon = (int) ( $args[0] ?? 0 ); $pos = (int) ( $args[1] ?? 0 );
	$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) ) ) );
	if ( ! $mon || $pos < 1 || ! isset( $ids[ $pos - 1 ] ) ) { WP_CLI::error( 'Use: foto <ID do monumento> <número da foto na galeria, 1 a ' . max( 1, count( $ids ) ) . '>' ); }
	update_post_meta( $mon, '_mve_qr_foto', $ids[ $pos - 1 ] );
	WP_CLI::log( "Foto $pos escolhida para o QR de " . get_the_title( $mon ) . '.' );
	$args = array( $mon ); $acao = 'gerar';
}
if ( 'comunidade' === $acao ) {
	$args = get_posts( array( 'post_type' => 'mv3_monument', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_mve_comunidade', 'meta_value' => '1' ) );
	$acao = 'gerar';
}
$ids = array_filter( array_map( 'intval', $args ) );
if ( ! in_array( $acao, array( 'gerar', 'ver' ), true ) || ! $ids ) { WP_CLI::error( 'Use: gerar|ver seguido dos números (ID), foto <ID> <nº>, ou: comunidade' ); }
foreach ( $ids as $id ) {
	if ( 'mv3_monument' !== get_post_type( $id ) ) { WP_CLI::warning( "$id não é um monumento." ); continue; }
	$t = microtime( true );
	$q = 'gerar' === $acao ? mve_qr_gerar( $id, true ) : get_post_meta( $id, '_mve_qr', true );
	if ( ! is_array( $q ) ) { WP_CLI::warning( "#$id " . get_the_title( $id ) . ': sem QR' . ( 'gerar' === $acao ? ' (veja o registro de erros)' : '' ) ); continue; }
	$fotos = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_mve_fotos', true ) ) ) );
	$pos   = array_search( (int) $q['foto'], $fotos, true );
	$como  = 'foto' === $q['modelo'] ? 'QR com a foto ' . ( false !== $pos ? $pos + 1 : '?' ) . ' de ' . count( $fotos ) : 'QR desenhado' . ( ! empty( $q['fotos_testadas'] ) ? ' (nenhuma das ' . $q['fotos_testadas'] . ' fotos deu leitura segura)' : '' );
	WP_CLI::success( sprintf( '#%d %s · %s · %s KB%s', $id, get_the_title( $id ), $como, round( filesize( $q['arquivo'] ) / 1024 ), 'gerar' === $acao ? sprintf( ' (%.1fs)', microtime( true ) - $t ) : '' ) );
	WP_CLI::log( '   QR: ' . $q['url'] );
	WP_CLI::log( '   abre: ' . $q['link'] . ' · impressão: ' . $q['url_impressao'] );
}
