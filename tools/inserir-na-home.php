<?php
/**
 * Insere o bloco "Mapa Wi-Fi v2.0 · camadas" na página inicial, logo abaixo do mapa atual ([mv3_map]).
 * Uso (dentro de public_html):  wp eval-file mapa-wifi/tools/inserir-na-home.php
 * Desfazer:                    wp eval-file mapa-wifi/tools/remover-da-home.php
 * Não altera nada se o bloco já estiver lá ou se o [mv3_map] não for encontrado.
 */
$id   = (int) get_option( 'page_on_front' );
$post = get_post( $id );
if ( ! $post ) { WP_CLI::error( 'Página inicial não encontrada. Nada foi alterado.' ); }

$c = $post->post_content;
if ( strpos( $c, 'class="mv-wifi"' ) !== false ) {
	WP_CLI::warning( 'O bloco do Mapa Wi-Fi já está na página inicial. Nada foi alterado.' );
	return;
}

$file = __DIR__ . '/../embed/bloco-home.html';
$html = is_readable( $file ) ? file_get_contents( $file ) : '';
if ( ! $html ) { WP_CLI::error( 'Arquivo do bloco não encontrado: ' . $file ); }
$html = trim( preg_replace( '/^\s*<!--.*?-->\s*/s', '', $html ) ); // tira o comentário de instruções do topo

$anchor = '<!-- /wp:shortcode -->';
$pos    = strpos( $c, $anchor );
if ( $pos === false || strpos( $c, '[mv3_map]' ) === false ) {
	WP_CLI::error( 'O mapa atual ([mv3_map]) não foi encontrado na página inicial. Nada foi alterado.' );
}

$block = "\n\n<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
$new   = substr( $c, 0, $pos + strlen( $anchor ) ) . $block . substr( $c, $pos + strlen( $anchor ) );

kses_remove_filters(); // o bloco usa <iframe> e <style>; sem isto o WordPress os removeria ao salvar
$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }

WP_CLI::success( "Mapa Wi-Fi v2.0 inserido na página inicial (ID {$id}), logo abaixo do mapa atual." );
