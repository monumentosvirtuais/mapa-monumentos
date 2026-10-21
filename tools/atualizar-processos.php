<?php
/**
 * Atualiza a página Processos (ID 246) com o texto novo (embed/processos.html).
 * Mantém a foto do Cais José Estelita (vai para a seção de 2014) e a régua "Realização" no fim.
 * Uso:  wp eval-file mapa-wifi/tools/atualizar-processos.php            (só confere)
 *       wp eval-file mapa-wifi/tools/atualizar-processos.php aplicar    (troca; guarda cópia da versão atual em ~/backups)
 *       wp eval-file mapa-wifi/tools/atualizar-processos.php desfazer   (volta para a cópia guardada mais recente)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$modo = isset( $args[0] ) ? $args[0] : '';
$post = get_post( 246 );
if ( ! $post || 'processos' !== $post->post_name ) { WP_CLI::error( 'Página Processos (ID 246) não encontrada.' ); }
$bak_dir = rtrim( getenv( 'HOME' ) ?: dirname( ABSPATH, 3 ), '/' ) . '/backups';

if ( 'desfazer' === $modo ) {
	$c = glob( $bak_dir . '/processos-antes-*.html' );
	if ( ! $c ) { WP_CLI::error( 'Nenhuma cópia guardada.' ); }
	sort( $c ); $f = end( $c );
	kses_remove_filters();
	wp_update_post( wp_slash( array( 'ID' => 246, 'post_content' => file_get_contents( $f ) ) ) );
	WP_CLI::success( 'Página Processos voltou para a versão de ' . basename( $f ) );
	return;
}

$novo = file_get_contents( dirname( __DIR__ ) . '/embed/processos.html' );
if ( ! $novo ) { WP_CLI::error( 'Arquivo embed/processos.html não encontrado.' ); }
$atual = $post->post_content;

// partes da página atual que continuam
$img = preg_match( '/<!-- wp:image\b.*?<!-- \/wp:image -->/s', $atual, $m ) ? $m[0] : '';
$reg = '';
if ( preg_match( '/<!-- wp:group\b[^>]*mv3-regua.*?-->.*?<!-- \/wp:group -->/s', $atual, $m2 ) ) { $reg = $m2[0]; }
elseif ( preg_match( '/<div class="[^"]*mv3-regua.*?<\/figure>\s*<\/div>/s', $atual, $m3 ) ) { $reg = "<!-- wp:html -->\n" . $m3[0] . "\n<!-- /wp:html -->"; }

$novo = str_replace( '<!-- MV:IMAGEM -->', $img, $novo );
$novo = str_replace( '<!-- MV:REGUA -->', $reg, $novo );

WP_CLI::log( 'Página atual: ' . substr_count( $atual, '<h2' ) . ' seções · nova: ' . substr_count( $novo, '<h2' ) . ' seções' );
WP_CLI::log( 'Foto do Cais José Estelita: ' . ( $img ? 'mantida (seção 2014)' : 'não encontrada na página atual' ) );
WP_CLI::log( 'Régua "Realização": ' . ( $reg ? 'mantida (no fim)' : 'não encontrada na página atual' ) );
WP_CLI::log( 'Números automáticos: ' . ( shortcode_exists( 'mve_contagem' ) ? do_shortcode( '[mve_contagem tipo="monumentos"] monumentos, [mve_contagem tipo="artistas"] artistas, [mve_contagem tipo="pontos"] pontos de acesso' ) : 'ATENÇÃO: atualize o mapa-wifi (git pull) antes' ) );
if ( ! shortcode_exists( 'mve_contagem' ) ) { WP_CLI::error( 'O contador [mve_contagem] não está ativo. Rode git pull na pasta mapa-wifi.' ); }

if ( 'aplicar' !== $modo ) { WP_CLI::success( 'Conferência ok. Nada foi alterado. Para trocar, rode de novo com: aplicar' ); return; }

if ( ! is_dir( $bak_dir ) ) { mkdir( $bak_dir, 0700, true ); }
$bak = $bak_dir . '/processos-antes-' . gmdate( 'Ymd-His' ) . '.html';
file_put_contents( $bak, $atual );
kses_remove_filters();
$r = wp_update_post( wp_slash( array( 'ID' => 246, 'post_content' => $novo ) ), true );
if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }
WP_CLI::success( "Página Processos atualizada. Cópia da versão anterior: $bak (para voltar: rode com desfazer)" );
