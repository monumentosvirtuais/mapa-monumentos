<?php
/**
 * Remove da página inicial o bloco "Mapa Wi-Fi v2.0 · camadas" inserido por inserir-na-home.php.
 * Uso (dentro de public_html):  wp eval-file mapa-wifi/tools/remover-da-home.php
 */
$id   = (int) get_option( 'page_on_front' );
$post = get_post( $id );
if ( ! $post ) { WP_CLI::error( 'Página inicial não encontrada.' ); }

$c   = $post->post_content;
$new = preg_replace( '/\n*<!-- wp:html -->\s*<section class="mv-wifi".*?<!-- \/wp:html -->/s', '', $c, 1, $n );
if ( ! $n ) { WP_CLI::warning( 'O bloco do Mapa Wi-Fi não está na página inicial. Nada foi alterado.' ); return; }

kses_remove_filters();
$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }
WP_CLI::success( "Bloco do Mapa Wi-Fi removido da página inicial (ID {$id})." );
