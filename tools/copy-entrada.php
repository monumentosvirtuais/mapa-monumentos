<?php
/**
 * Nova copy de entrada da página inicial (cabeçalho do [mv3_map], no plugin monumentos-virtuais-v3).
 * Troca só o parágrafo "Performances e vídeos-arte espalhados pelo território…" por:
 *   Museu virtual de arte e memória
 *   Quais histórias merecem se tornar monumentos?
 *   Corpos que ocupam, memórias que resistem, lugares que se transformam. Um museu de
 *   performances e videoartes que convida a repensar o que escolhemos lembrar.
 *   Encontre uma obra. Conheça uma história. Descubra um território.
 *   [Descubra o acervo] → /monumentos/
 * "Arte · Corpo · Território" e "MONUMENTOS VIRTUAIS" continuam como estão.
 * Antes de gravar, guarda uma cópia do plugin em ~/backups/ (fora do site).
 * Uso:  wp eval-file mapa-wifi/tools/copy-entrada.php          (só confere)
 *       wp eval-file mapa-wifi/tools/copy-entrada.php aplicar  (grava)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$aplicar = isset( $args[0] ) && 'aplicar' === $args[0];
$arq     = WP_PLUGIN_DIR . '/monumentos-virtuais-v3/monumentos-virtuais-v3.php';
if ( ! is_readable( $arq ) ) { WP_CLI::error( "Plugin não encontrado: $arq" ); }
$php = file_get_contents( $arq );

if ( false !== strpos( $php, 'mv3-hero__q' ) ) {
	WP_CLI::success( 'A nova copy de entrada já está no plugin. Nada a fazer.' );
	return;
}

$re = '#(?<ind>[ \t]*)<p class="mv3-hero__lede">Performances e vídeos-arte espalhados pelo território\..*?</p>#u';
$n  = preg_match_all( $re, $php, $m );
if ( 1 !== $n ) { WP_CLI::error( "Esperava 1 parágrafo de entrada no plugin; achei $n. Nada foi alterado." ); }
$i = $m['ind'][0];

$novo = $i . '<p class="mv3-hero__sub">Museu virtual de arte e memória</p>' . "\n"
	. $i . '<h2 class="mv3-hero__q">Quais histórias merecem se tornar monumentos?</h2>' . "\n"
	. $i . '<p class="mv3-hero__lede">Corpos que ocupam, memórias que resistem, lugares que se transformam. Um museu de performances e videoartes que convida a repensar o que escolhemos lembrar.</p>' . "\n"
	. $i . '<p class="mv3-hero__lede mv3-hero__lede--forte">Encontre uma obra. Conheça uma história. Descubra um território.</p>' . "\n"
	. $i . '<a class="mv3-hero__cta" href="<?php echo esc_url( home_url( \'/monumentos/\' ) ); ?>">Descubra o acervo</a>' . "\n"
	. $i . '<style>'
	. '.mv3-hero .mv3-hero__sub{margin:12px 0 0;font-size:clamp(14px,1.5vw,17px);letter-spacing:.14em;text-transform:uppercase;color:#C67033}'
	. '.mv3-hero .mv3-hero__q{margin:30px 0 12px;max-width:26ch;font-size:clamp(22px,3vw,34px);line-height:1.2;font-weight:700;letter-spacing:0;text-transform:none;color:#F2EDE6}'
	. '.mv3-hero .mv3-hero__lede{max-width:62ch}'
	. '.mv3-hero .mv3-hero__lede+.mv3-hero__lede{margin-top:10px}'
	. '.mv3-hero .mv3-hero__lede--forte{color:#F2EDE6}'
	. '.mv3-hero .mv3-hero__cta{display:inline-block;margin-top:22px;padding:14px 24px;border:1px solid #C67033;background:#C67033;color:#FFF4EA;font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;text-decoration:none}'
	. '.mv3-hero .mv3-hero__cta:hover{background:transparent;color:#F2EDE6}'
	. '</style>';

$php2 = preg_replace( $re, str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $novo ), $php, 1 );

WP_CLI::log( 'Entrada nova: Museu virtual de arte e memória / Quais histórias merecem se tornar monumentos? / texto / Descubra o acervo → /monumentos/' );
if ( ! $aplicar ) {
	WP_CLI::success( 'Conferência ok. Para gravar, rode de novo com: aplicar' );
	return;
}

$dir = rtrim( getenv( 'HOME' ) ?: dirname( ABSPATH, 3 ), '/' ) . '/backups';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) { WP_CLI::error( "Não consegui criar $dir" ); }
$bak = $dir . '/monumentos-virtuais-v3.php.antes-copy-entrada-' . gmdate( 'Ymd-His' );
if ( ! copy( $arq, $bak ) ) { WP_CLI::error( 'Não consegui guardar a cópia de segurança. Nada foi alterado.' ); }
if ( false === file_put_contents( $arq, $php2 ) ) { WP_CLI::error( 'Não consegui gravar o plugin. Nada foi alterado.' ); }
WP_CLI::success( "Copy de entrada atualizada. Cópia do plugin anterior: $bak" );
