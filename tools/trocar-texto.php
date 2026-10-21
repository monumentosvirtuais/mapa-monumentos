<?php
/**
 * Troca um texto do site, onde quer que ele esteja: páginas/posts (inclusive Elementor), campos,
 * opções, e arquivos do plugin monumentos-virtuais-v3 e do tema ativo.
 * Uso:
 *   wp eval-file mapa-wifi/tools/trocar-texto.php "texto antigo" "texto novo"           (só mostra onde está)
 *   wp eval-file mapa-wifi/tools/trocar-texto.php "texto antigo" "texto novo" aplicar   (troca; arquivos ganham cópia em ~/backups)
 * Também lista os scripts do plugin que interceptam cliques (para o botão "Descubra o acervo").
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb;

$de      = isset( $args[0] ) ? (string) $args[0] : '';
$para    = isset( $args[1] ) ? (string) $args[1] : '';
$aplicar = isset( $args[2] ) && 'aplicar' === $args[2];
if ( mb_strlen( $de ) < 8 ) { WP_CLI::error( 'Passe o texto antigo (pelo menos 8 letras) e o novo, entre aspas.' ); }

// O Elementor guarda o texto em JSON, com acentos escritos como ç e "/" como "\/".
$variantes = array( $de => $para );
$je = function ( $t ) { return substr( wp_json_encode( $t ), 1, -1 ); };
if ( $je( $de ) !== $de ) { $variantes[ $je( $de ) ] = $je( $para ); }
$jee = function ( $t ) { return substr( json_encode( $t ), 1, -1 ); };
if ( $jee( $de ) !== $de ) { $variantes[ $jee( $de ) ] = $jee( $para ); }

$achou = 0;
$troca_rec = function ( $v ) use ( &$troca_rec, $variantes ) {
	if ( is_string( $v ) ) { return strtr( $v, $variantes ); }
	if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = $troca_rec( $x ); } }
	return $v;
};

// 1) posts e páginas
foreach ( $variantes as $d => $p ) {
	$like = '%' . $wpdb->esc_like( $d ) . '%';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s OR post_title LIKE %s", $like, $like, $like ) );
	foreach ( $rows as $r ) {
		if ( 'revision' === $r->post_type ) { continue; }
		$achou++;
		WP_CLI::log( "POST  #{$r->ID} ({$r->post_type}, {$r->post_status}) {$r->post_title}" );
		if ( $aplicar ) {
			$post = get_post( $r->ID );
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $r->ID, 'post_content' => strtr( $post->post_content, $variantes ), 'post_excerpt' => strtr( $post->post_excerpt, $variantes ), 'post_title' => strtr( $post->post_title, $variantes ) ) ) );
		}
	}
	// 2) campos (inclui _elementor_data)
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", $like ) );
	foreach ( $rows as $r ) {
		$achou++;
		WP_CLI::log( "CAMPO post #{$r->post_id} {$r->meta_key}" );
		if ( $aplicar ) {
			$v = get_post_meta( $r->post_id, $r->meta_key, true );
			update_post_meta( $r->post_id, $r->meta_key, wp_slash( $troca_rec( $v ) ) );
			if ( '_elementor_data' === $r->meta_key ) { delete_post_meta( $r->post_id, '_elementor_css' ); }
		}
	}
	// 3) opções
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s", $like, '\\_transient%' ) );
	foreach ( $rows as $r ) {
		$achou++;
		WP_CLI::log( "OPÇÃO {$r->option_name}" );
		if ( $aplicar ) { update_option( $r->option_name, $troca_rec( get_option( $r->option_name ) ) ); }
	}
}

// 4) arquivos do plugin e do tema
$pastas = array( WP_PLUGIN_DIR . '/monumentos-virtuais-v3', get_stylesheet_directory() );
if ( get_template_directory() !== get_stylesheet_directory() ) { $pastas[] = get_template_directory(); }
$bak = rtrim( getenv( 'HOME' ) ?: dirname( ABSPATH, 3 ), '/' ) . '/backups';
foreach ( $pastas as $dir ) {
	if ( ! is_dir( $dir ) ) { continue; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( ! preg_match( '/\.(php|html|js|json)$/', $f->getFilename() ) ) { continue; }
		$c = file_get_contents( $f->getPathname() );
		$n = 0; foreach ( array_keys( $variantes ) as $d ) { $n += substr_count( $c, $d ); }
		if ( ! $n ) { continue; }
		$achou++;
		WP_CLI::log( 'ARQUIVO ' . str_replace( ABSPATH, '', $f->getPathname() ) . " ($n)" );
		if ( $aplicar ) {
			if ( ! is_dir( $bak ) ) { mkdir( $bak, 0700, true ); }
			copy( $f->getPathname(), $bak . '/' . $f->getFilename() . '.antes-troca-' . gmdate( 'Ymd-His' ) );
			file_put_contents( $f->getPathname(), strtr( $c, $variantes ) );
		}
	}
}

if ( ! $achou ) { WP_CLI::warning( 'Texto não encontrado. Confira se foi copiado igual, com a pontuação.' ); }
elseif ( $aplicar ) { WP_CLI::success( "Trocado em $achou lugar(es). Cópias dos arquivos em $bak" ); }
else { WP_CLI::success( "Encontrado em $achou lugar(es). Para trocar, rode de novo com: aplicar" ); }

// 5) quem intercepta cliques (diagnóstico do botão "Descubra o acervo")
WP_CLI::log( "\n--- scripts do plugin que mexem em cliques/rolagem ---" );
foreach ( glob( WP_PLUGIN_DIR . '/monumentos-virtuais-v3/assets/*.js' ) as $js ) {
	foreach ( file( $js ) as $i => $l ) {
		if ( preg_match( '/preventDefault|scrollIntoView|scrollTo\(|mv3-hero|\.hero|addEventListener\(\s*[\'"]click/', $l ) ) {
			WP_CLI::log( basename( $js ) . ':' . ( $i + 1 ) . ': ' . mb_substr( trim( $l ), 0, 140 ) );
		}
	}
}
