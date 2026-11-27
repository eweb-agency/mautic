<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

/**
 * Ce qui revient du modèle passe ici avant d'entrer dans l'éditeur.
 *
 * - On ne garde que la forme demandée (la section et sa feuille de style,
 *   ou la section MJML) : un mot d'introduction ou une fin bavarde ne
 *   s'insère jamais dans la page.
 * - Rien d'exécutable : scripts, cadres, gestionnaires d'événements, liens
 *   `javascript:`, imports et URL externes dans la feuille de style.
 * - Les emplacements d'image deviennent de vraies images : une image
 *   neutre que l'utilisateur remplace d'un double clic (page : image
 *   embarquée ; e-mail : fichier servi par l'espace, une messagerie ne lit
 *   pas d'image embarquée).
 * - MJML : aucune balise auto-fermante (l'éditeur les lit mal, bug connu
 *   du fork).
 */
final class GeneratedMarkup
{
    public const IMAGE_DIR = '/plugins/EwebAiBundle/Assets/img';

    public static function page(string $html): string
    {
        $html  = self::keepSpan($html, '<section', ['</style>', '</section>']);
        $html  = self::disarm($html);
        $html  = (string) preg_replace_callback('/<style\b[^>]*>([\s\S]*?)<\/style>/i', static fn (array $m): string => '<style>'.self::safeCss($m[1]).'</style>', $html);

        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m): string {
            $tag   = $m[0];
            $ratio = self::attr($tag, 'data-sendly-image');
            $src   = self::attr($tag, 'src');
            $local = null !== $src && '' !== $src && !preg_match('#^(?:https?:)?//#i', $src);
            if ($local && null === $ratio) {
                return $tag;
            }
            $tag = (string) preg_replace('/\ssrc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);

            return (string) preg_replace('/^<img\b/i', '<img src="'.self::svgPlaceholder($ratio ?? '16:9').'"', $tag);
        }, $html);
    }

    public static function emailSection(string $markup, string $format, string $baseUrl): string
    {
        if ('mjml' === $format) {
            $markup = self::keepSpan($markup, '<mj-section', ['</mj-section>']);
            $markup = self::openMjmlTags($markup);
        } else {
            $markup = self::keepSpan($markup, '<table', ['</table>']);
        }

        return self::emailImages(self::disarm($markup), $baseUrl);
    }

    public static function emailBody(string $markup, string $format, string $baseUrl): string
    {
        if ('mjml' === $format) {
            $markup = self::keepSpan($markup, '<mjml', ['</mjml>']);
            $markup = self::openMjmlTags($markup);
        }

        return self::emailImages(self::disarm($markup), $baseUrl);
    }

    /**
     * L'image neutre d'une page : une toile au bon rapport, un pictogramme
     * d'image au centre. Embarquée, elle ne dépend d'aucun fichier.
     */
    public static function svgPlaceholder(string $ratio): string
    {
        [$w, $h] = GenerationDesign::RATIOS[self::ratio($ratio)];
        $x       = (int) ($w / 2 - 36);
        $y       = (int) ($h / 2 - 28);
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'">'
            .'<rect width="'.$w.'" height="'.$h.'" fill="#ECE9E3"/>'
            .'<g transform="translate('.$x.' '.$y.')" fill="none" stroke="#A8A196" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">'
            .'<rect x="2" y="2" width="68" height="52" rx="8"/><circle cx="22" cy="20" r="6"/><path d="M70 40 52 24 14 54"/></g></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    /** Le fichier d'image neutre d'un e-mail, au rapport le plus proche. */
    public static function emailPlaceholderUrl(string $ratio, string $baseUrl): string
    {
        return rtrim($baseUrl, '/').self::IMAGE_DIR.'/emplacement-'.str_replace(':', 'x', self::ratio($ratio)).'.png';
    }

    /** « 16:9 », « 600x340 » ou n'importe quoi : le rapport connu le plus proche. */
    public static function ratio(string $demande): string
    {
        $demande = trim($demande);
        if (isset(GenerationDesign::RATIOS[$demande])) {
            return $demande;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*[:x\/]\s*(\d+(?:\.\d+)?)$/i', $demande, $m) || 0.0 === (float) $m[2]) {
            return '16:9';
        }
        $voulu    = (float) $m[1] / (float) $m[2];
        $meilleur = '16:9';
        $ecart    = INF;
        foreach (GenerationDesign::RATIOS as $nom => [$w, $h]) {
            $d = abs($w / $h - $voulu);
            if ($d < $ecart) {
                $ecart    = $d;
                $meilleur = $nom;
            }
        }

        return $meilleur;
    }

    /**
     * Du premier `$debut` à la DERNIÈRE fermeture trouvée parmi `$fins` :
     * le préambule et la conclusion du modèle tombent. Sans ouverture, le
     * texte reste tel quel (l'éditeur saura le refuser).
     *
     * @param list<string> $fins
     */
    private static function keepSpan(string $s, string $debut, array $fins): string
    {
        $i = stripos($s, $debut);
        if (false === $i) {
            return trim($s);
        }
        $fin = -1;
        foreach ($fins as $f) {
            $j = strripos($s, $f);
            if (false !== $j && $j + strlen($f) > $fin) {
                $fin = $j + strlen($f);
            }
        }
        if ($fin <= $i) {
            return trim(substr($s, $i));
        }

        return trim(substr($s, $i, $fin - $i));
    }

    /** Retire tout ce qui s'exécute. */
    private static function disarm(string $s): string
    {
        $s = (string) preg_replace('/<(script|iframe|object|embed)\b[\s\S]*?<\/\1\s*>/i', '', $s);
        $s = (string) preg_replace('/<(script|iframe|object|embed|link|meta|base)\b[^>]*>/i', '', $s);
        $s = (string) preg_replace('/<\/?form\b[^>]*>/i', '', $s);
        $s = (string) preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $s);

        return (string) preg_replace('/(\s(?:href|src|action|formaction)\s*=\s*["\']?)\s*javascript:[^"\'\s>]*/i', '$1#', $s);
    }

    /** La feuille d'une section : ni import, ni ressource externe. */
    private static function safeCss(string $css): string
    {
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/url\(\s*["\']?(?:https?:)?\/\/[^)]*\)/i', 'none', $css);
        $css = (string) preg_replace('/expression\s*\(/i', '(', $css);

        return str_replace('</', '', $css);
    }

    /** `<mj-image … />` → `<mj-image …></mj-image>`. */
    private static function openMjmlTags(string $s): string
    {
        return (string) preg_replace('/<(mj-[a-z-]+)(\s[^<>]*?)?\s*\/>/i', '<$1$2></$1>', $s);
    }

    private static function emailImages(string $s, string $baseUrl): string
    {
        return (string) preg_replace_callback(
            '/(\ssrc\s*=\s*)(["\'])sendly-image:([^"\']*)\2/i',
            static fn (array $m): string => $m[1].$m[2].self::emailPlaceholderUrl($m[3], $baseUrl).$m[2],
            $s
        );
    }

    private static function attr(string $tag, string $nom): ?string
    {
        if (!preg_match('/\s'.preg_quote($nom, '/').'\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return null;
        }

        return $m[2] ?? '' ?: ($m[3] ?? '' ?: ($m[4] ?? ''));
    }
}
