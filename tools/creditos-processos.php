<?php
/**
 * Ajusta os créditos da página Processos (ID 246):
 *  - V2 MV: "Programador e designer web — Alexandre Salomão"
 *  - Equipe da primeira série — Corpo Monumento: acrescenta
 *    "Programador e designer web — Alexandre Salomão e Zé Diniz" (última linha).
 * Uso:  wp eval-file mapa-wifi/tools/creditos-processos.php          (só mostra o que vai mudar)
 *       wp eval-file mapa-wifi/tools/creditos-processos.php aplicar  (grava; o WordPress guarda revisão)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$aplicar = isset( $args[0] ) && 'aplicar' === $args[0];
$post    = get_post( 246 );
if ( ! $post || 'processos' !== $post->post_name ) { WP_CLI::error( 'Página Processos (ID 246) não encontrada.' ); }
$c = $post->post_content;

$tr   = '(?:—|&#8212;|&mdash;)';
$prog = '<strong>Programador e designer web</strong>\s*' . $tr . '\s*';

// Já aplicado? (linha nova logo depois de "Correção de cor…")
if ( preg_match( '~finalização</strong>\s*' . $tr . '\s*Zé Diniz\s*<br\s*/?>\s*' . $prog . 'Alexandre Salomão e Zé Diniz~u', $c ) ) {
	WP_CLI::success( 'Os créditos já estão atualizados. Nada a fazer.' );
	return;
}

// 1) V2 MV: só Alexandre Salomão
$n1 = preg_match_all( '~(' . $prog . ')Alexandre Salomão e Zé Diniz~u', $c );
if ( 1 !== $n1 ) { WP_CLI::error( "Esperava 1 linha 'Programador e designer web — Alexandre Salomão e Zé Diniz' (V2 MV); achei $n1. Nada foi alterado." ); }
$c = preg_replace( '~(' . $prog . ')Alexandre Salomão e Zé Diniz~u', '$1Alexandre Salomão', $c, 1 );

// 2) Primeira série: nova linha depois de "Correção de cor, color grading e finalização — Zé Diniz"
$re2 = '~(<strong>Correção de cor, color grading e finalização</strong>\s*' . $tr . '\s*Zé Diniz)(\s*</p>)~u';
$n2  = preg_match_all( $re2, $c );
if ( 1 !== $n2 ) { WP_CLI::error( "Esperava 1 linha 'Correção de cor… — Zé Diniz' (primeira série); achei $n2. Nada foi alterado." ); }
$c = preg_replace( $re2, '$1<br><strong>Programador e designer web</strong> — Alexandre Salomão e Zé Diniz$2', $c, 1 );

WP_CLI::log( 'V2 MV          → Programador e designer web — Alexandre Salomão' );
WP_CLI::log( 'Primeira série → + Programador e designer web — Alexandre Salomão e Zé Diniz (depois de Correção de cor)' );

if ( ! $aplicar ) {
	WP_CLI::success( 'Conferência ok. Para gravar, rode de novo com: aplicar' );
	return;
}

kses_remove_filters();
$r = wp_update_post( wp_slash( array( 'ID' => 246, 'post_content' => $c ) ), true );
if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }
WP_CLI::success( 'Créditos da página Processos atualizados (a versão anterior fica nas revisões da página).' );
