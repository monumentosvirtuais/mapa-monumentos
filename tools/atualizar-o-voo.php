<?php
/**
 * Atualiza O VOO e o perfil de Elis Costa: minibio, ficha técnica e a 1ª foto da galeria.
 * Rode na pasta public_html:
 *   wp eval-file mapa-wifi/tools/atualizar-o-voo.php ver        → mostra o que vai mudar (não muda nada)
 *   wp eval-file mapa-wifi/tools/atualizar-o-voo.php aplicar    → aplica (guarda uma cópia do que havia antes)
 *   wp eval-file mapa-wifi/tools/atualizar-o-voo.php desfazer   → volta ao que havia antes
 */
$acao = isset( $args[0] ) ? $args[0] : 'ver';
$mon  = isset( $args[1] ) ? (int) $args[1] : 471; // O VOO
$dir  = __DIR__ . '/entrada/o-voo';
$bkp  = rtrim( (string) getenv( 'HOME' ), '/' ) . '/backups/o-voo-antes.json';

$p = get_post( $mon );
if ( ! $p || 'mv3_monument' !== $p->post_type || 'O VOO' !== $p->post_title ) { WP_CLI::error( "O monumento #$mon não é O VOO." ); }
$art = (int) get_post_meta( $mon, '_mv3_artist_id', true );
if ( ! $art || 'Elis Costa' !== get_the_title( $art ) ) { WP_CLI::error( 'O perfil ligado ao O VOO não é Elis Costa.' ); }

$bio   = trim( (string) file_get_contents( "$dir/bio.txt" ) );
$ficha = trim( str_replace( "\r", '', (string) file_get_contents( "$dir/ficha.txt" ) ) );
$fotos = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) ) ) );

if ( 'desfazer' === $acao ) {
	if ( ! is_file( $bkp ) ) { WP_CLI::error( 'Não há cópia anterior para voltar.' ); }
	$b = json_decode( file_get_contents( $bkp ), true );
	wp_update_post( array( 'ID' => $art, 'post_content' => $b['bio'] ) );
	update_post_meta( $mon, '_mv3_ficha', $b['ficha'] );
	update_post_meta( $mon, '_mve_fotos', $b['fotos'] );
	if ( $b['capa'] ) { set_post_thumbnail( $mon, $b['capa'] ); }
	if ( ! empty( $b['nova'] ) && ! in_array( (int) $b['nova'], array_map( 'intval', $b['fotos'] ), true ) ) { wp_delete_attachment( (int) $b['nova'], true ); }
	if ( function_exists( 'mve_qr_gerar' ) ) { mve_qr_gerar( $mon, true ); }
	WP_CLI::success( 'O VOO e o perfil de Elis Costa voltaram ao que eram antes.' );
	return;
}

WP_CLI::log( 'Perfil: ' . get_the_title( $art ) . " (#$art)" );
WP_CLI::log( '  minibio: ' . mb_strlen( get_post_field( 'post_content', $art ) ) . ' → ' . mb_strlen( $bio ) . ' letras' );
WP_CLI::log( "Monumento: O VOO (#$mon)" );
WP_CLI::log( '  ficha: ' . count( array_filter( explode( "\n", (string) get_post_meta( $mon, '_mv3_ficha', true ) ) ) ) . ' → ' . count( explode( "\n", $ficha ) ) . ' funções' );
WP_CLI::log( '  fotos: ' . count( $fotos ) . ' hoje; sai a 1ª (' . ( $fotos ? basename( (string) get_attached_file( $fotos[0] ) ) : '—' ) . ') e entra foto-tunel.jpg no lugar dela' );
if ( 'aplicar' !== $acao ) { WP_CLI::log( 'Nada foi mudado. Para aplicar: rode com aplicar' ); return; }

// cópia do que havia antes
wp_mkdir_p( dirname( $bkp ) );
$antes = array( 'bio' => get_post_field( 'post_content', $art ), 'ficha' => (string) get_post_meta( $mon, '_mv3_ficha', true ), 'fotos' => $fotos, 'capa' => (int) get_post_thumbnail_id( $mon ) );

// foto nova
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$tmp = wp_tempnam( 'o-voo-tunel.jpg' );
copy( "$dir/foto-tunel.jpg", $tmp );
$nova = media_handle_sideload( array( 'name' => 'o-voo-tunel.jpg', 'tmp_name' => $tmp ), $mon, 'O VOO' );
if ( is_wp_error( $nova ) ) { @unlink( $tmp ); WP_CLI::error( 'A foto não entrou: ' . $nova->get_error_message() ); }
$antes['nova'] = (int) $nova;
file_put_contents( $bkp, wp_json_encode( $antes ) );

$novas = array_merge( array( (int) $nova ), array_slice( $fotos, 1 ) );
update_post_meta( $mon, '_mve_fotos', $novas );
set_post_thumbnail( $mon, (int) $nova );
update_post_meta( $mon, '_mv3_ficha', $ficha );
wp_update_post( array( 'ID' => $art, 'post_content' => $bio ) );
$q = function_exists( 'mve_qr_gerar' ) ? mve_qr_gerar( $mon, true ) : null;

WP_CLI::success( 'Minibio de Elis Costa, ficha técnica do O VOO e 1ª foto atualizadas. Cópia do que havia antes: ' . $bkp );
WP_CLI::log( '  galeria agora: ' . count( $novas ) . ' fotos (a foto retirada continua na Biblioteca de mídia, fora da galeria)' );
if ( is_array( $q ) ) { WP_CLI::log( '  QR refeito: ' . ( 'foto' === $q['modelo'] ? 'com a foto' : 'desenhado' ) . ' · ' . $q['url'] ); }
