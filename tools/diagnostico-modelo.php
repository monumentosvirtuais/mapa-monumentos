<?php
/**
 * Diagnóstico (só leitura): como o plugin monumentos-virtuais-v3 guarda monumentos e artistas.
 * Não altera nada. Mostra tipos de conteúdo, campos (meta) de um monumento e de um artista,
 * rotas da API e trechos do plugin que gravam/leem esses campos.
 * Uso: wp eval-file mapa-wifi/tools/diagnostico-modelo.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mvd_v( $v ) {
	if ( is_array( $v ) || is_object( $v ) ) { $v = wp_json_encode( $v, JSON_UNESCAPED_UNICODE ); }
	$v = (string) $v;
	$v = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $v );
	$v = preg_replace( '/\+?\d[\d\s().-]{8,}\d/', '[número]', $v );
	$v = str_replace( array( "\r", "\n" ), ' ', $v );
	return mb_strlen( $v ) > 110 ? mb_substr( $v, 0, 110 ) . '…' : $v;
}
function mvd_h( $t ) { WP_CLI::log( "\n=== $t ===" ); }

mvd_h( 'Tipos de conteúdo do site (não nativos)' );
foreach ( get_post_types( array( '_builtin' => false ), 'objects' ) as $pt ) {
	$n = wp_count_posts( $pt->name );
	WP_CLI::log( sprintf( '%-22s %-28s publicados=%d rascunhos=%d pendentes=%d rewrite=%s', $pt->name, $pt->label, $n->publish ?? 0, $n->draft ?? 0, $n->pending ?? 0, is_array( $pt->rewrite ) ? ( $pt->rewrite['slug'] ?? '' ) : '-' ) );
}

mvd_h( 'Taxonomias (não nativas)' );
foreach ( get_taxonomies( array( '_builtin' => false ), 'objects' ) as $tx ) {
	WP_CLI::log( sprintf( '%-22s em: %s', $tx->name, implode( ',', $tx->object_type ) ) );
}

$mon = null; $art = null;
foreach ( get_post_types( array( '_builtin' => false ) ) as $t ) {
	$p = get_posts( array( 'post_type' => $t, 'name' => 'afluir', 'numberposts' => 1, 'post_status' => 'any' ) );
	if ( $p ) { $mon = $p[0]; }
	$a = get_posts( array( 'post_type' => $t, 'name' => 'gabi-holanda', 'numberposts' => 1, 'post_status' => 'any' ) );
	if ( $a ) { $art = $a[0]; }
}

foreach ( array( 'Monumento de exemplo (afluir)' => $mon, 'Artista de exemplo (gabi-holanda)' => $art ) as $tit => $p ) {
	mvd_h( $tit );
	if ( ! $p ) { WP_CLI::log( 'não encontrado' ); continue; }
	WP_CLI::log( "ID={$p->ID} tipo={$p->post_type} status={$p->post_status} pai={$p->post_parent} autor={$p->post_author} imagem_destacada=" . get_post_thumbnail_id( $p->ID ) );
	WP_CLI::log( 'título: ' . mvd_v( $p->post_title ) );
	WP_CLI::log( 'conteúdo: ' . mvd_v( $p->post_content ) . ' (' . mb_strlen( $p->post_content ) . ' caracteres)' );
	WP_CLI::log( 'resumo: ' . mvd_v( $p->post_excerpt ) );
	foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
		if ( in_array( $k, array( '_edit_lock', '_edit_last' ), true ) ) { continue; }
		WP_CLI::log( sprintf( '  meta %-32s = %s', $k, mvd_v( maybe_unserialize( $vals[0] ) ) ) );
	}
	foreach ( get_object_taxonomies( $p->post_type ) as $tx ) {
		$ts = wp_get_post_terms( $p->ID, $tx, array( 'fields' => 'names' ) );
		if ( $ts && ! is_wp_error( $ts ) ) { WP_CLI::log( "  termo $tx = " . implode( ', ', $ts ) ); }
	}
}

mvd_h( 'Rotas da API mv3' );
foreach ( array_keys( rest_get_server()->get_routes() ) as $r ) {
	if ( 0 === strpos( $r, '/mv3' ) ) { WP_CLI::log( $r ); }
}

mvd_h( 'Plugin: onde grava/lê campos (register_post_type, meta, rotas)' );
$dir = WP_PLUGIN_DIR . '/monumentos-virtuais-v3';
$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' !== $f->getExtension() ) { continue; }
	foreach ( file( $f->getPathname() ) as $i => $linha ) {
		if ( preg_match( '/register_post_type|register_taxonomy|register_rest_route|register_post_meta|get_post_meta|update_post_meta|get_field\(|meta_key|wp_mail|MV3_VERSION\'?,/', $linha ) ) {
			WP_CLI::log( str_replace( $dir . '/', '', $f->getPathname() ) . ':' . ( $i + 1 ) . ': ' . mvd_v( trim( $linha ) ) );
		}
	}
}

mvd_h( 'Ambiente' );
WP_CLI::log( 'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ' · ACF: ' . ( function_exists( 'get_field' ) ? 'sim' : 'não' ) );
WP_CLI::log( 'Plugins ativos: ' . implode( ', ', array_map( 'dirname', (array) get_option( 'active_plugins' ) ) ) );
WP_CLI::log( 'mu-plugins: ' . ( is_dir( WPMU_PLUGIN_DIR ) ? implode( ', ', array_map( 'basename', glob( WPMU_PLUGIN_DIR . '/*.php' ) ) ) : '(pasta não existe)' ) );
WP_CLI::log( 'Envio de e-mail: ' . ( has_action( 'phpmailer_init' ) ? 'há configuração SMTP (phpmailer_init)' : 'padrão do servidor (sem SMTP configurado)' ) );
WP_CLI::log( 'Upload máx.: ' . size_format( wp_max_upload_size() ) );
WP_CLI::success( 'Diagnóstico concluído. Nada foi alterado.' );
