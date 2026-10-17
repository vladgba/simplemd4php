<?php
/**
 * SimpleMd4PHP — a single-file Markdown site engine.
 *
 * Renders .md files as HTML pages. Supports traditional Markdown,
 * GitHub Flavored Markdown and Markdown Extra (modelled on cebe/markdown).
 *
 *   /            -> index.md        (or README.md)
 *   /about       -> about.md
 *   /docs/       -> docs/index.md   (or docs/README.md)
 *   not found    -> 404.md          (if present)
 *
 * Needs PHP 7.4+ and the .htaccess next to this file (Apache + mod_rewrite).
 * License: MIT
 */

const SITE_NAME = 'SimpleMd4PHP';           // appended to every page title
const SITE_LANG = 'en';                     // <html lang="…">
const CACHE_DIR = __DIR__ . '/.cache';      // rendered-HTML cache; '' disables it
const MD_BREAKS = false;                    // GFM option: every newline becomes <br>
const MD_ANCHORS = true;                    // headings get ids (#my-heading) and a "#" permalink

/* ------------------------------------------------------------------------- */
/*  Markdown parser                                                          */
/* ------------------------------------------------------------------------- */

final class Markdown
{
    /** Treat single newlines inside paragraphs as hard line breaks (GFM option). */
    public $breaks = false;

    /** Give every heading a GitHub-style id ("My Heading" -> #my-heading) and a "#" permalink. */
    public $anchors = false;

    private const TAGS = 'address|article|aside|audio|blockquote|body|canvas|center|dd|details|dialog|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|html|iframe|legend|li|link|main|menu|nav|noscript|ol|p|pre|script|section|style|summary|svg|table|tbody|td|template|textarea|tfoot|th|thead|tr|ul|video';
    private const LI    = '/^( {0,3})([*+-]|(\d{1,9})[.)])([ \t]+|$)/';
    private const HR    = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';
    private const FENCE = '/^( {0,3})(`{3,}(?=[^`]*$)|~{3,})[ \t]*(.*?)[ \t]*$/';
    private const DD    = '/^ {0,3}:[ \t]+/';
    private const SEP   = '/^ *\|? *:?-+:? *(?:\| *:?-+:? *)*\|? *$/';
    private const ALPHA = '(?:\s*,\s*(?:0|1|0?\.\d+|\d{1,3}%))?\s*\)';
    private const COLOR = '/^(?:#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})'                    // `#rgb`, `#rrggbb`
        . '|rgba?\(\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*,\s*\d{1,3}%?' . self::ALPHA                  // `rgb(9, 105, 218)`
        . '|hsla?\(\s*\d{1,3}(?:deg)?\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%' . self::ALPHA . ')$/i';    // `hsl(212, 92%, 45%)`

    // All inline syntax in one left-to-right pass; matched spans become placeholders.
    private const INLINE = '/'
        . '\\\\(?<esc>[\\\\`*_{}\[\]()#+\-.!<>|:~"\'\n])'                          // \* escapes, "\" + newline = <br>
        . '|(?<tick>`+)(?<code>.+?)(?<!`)\k<tick>(?!`)'                           // `code`
        . '|<(?<auto>(?:https?|ftp):\/\/[^\s<>]+|mailto:[^\s<>]+)>'               // <http://…>
        . '|<(?<mail>[^\s<>@\/]+@[^\s<>@]+\.[^\s<>@]+)>'                          // <me@example.com>
        . '|(?<html><!--.*?-->|<\/?[A-Za-z][A-Za-z0-9-]*(?:\s[^<>]*)?>)'          // inline HTML
        . '|\[\^(?<fn>[^\]\s]+)\]'                                                 // [^footnote]
        . '|(?<img>!?)\[(?<txt>(?:[^\[\]]|\[(?:[^\[\]]|\[[^\[\]]*\])*\])*)\]'      // [text] / ![alt]
        .   '(?:\([ \t]*<?(?<url>(?:[^\s()<>]|\([^\s()<>]*\))*)>?'                 //   (url
        .   '(?:\s+(?<q>["\'])(?<title>.*?)\k<q>|\s+\((?<title2>[^)]*)\))?[ \t]*\)' //   "title")
        .   '|\s?\[(?<ref>[^\]]*)\])?'                                             //   | [ref]
        .   '(?:\{(?<attr>[#.][^}]*)\})?'                                           //   {#id .class}
        . '|(?<![\w.+\-\/@:])(?<bmail>[\w.+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)+)(?![\w@\-]|\.[A-Za-z0-9])' // bare email (GFM)
        . '|(?<![\w\/"\'=@])(?<www>(?:https?:\/\/|www\.)(?:[^\s<()]|\([^\s<()]*\))+(?<![?!.,:;*_~\'"]))' // bare URL (GFM)
        . '/s';

    private $refs = [], $notes = [], $used = [], $abbr = [], $tok = [], $ids = [];
    private $abbrRe = '';
    private $inLink = false;

    public function parse(string $text): string
    {
        $this->refs = $this->notes = $this->used = $this->abbr = $this->tok = $this->ids = [];
        $this->abbrRe = '';

        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
        if (!preg_match('//u', $text)) {
            $text = function_exists('mb_scrub') ? mb_scrub($text, 'UTF-8') : preg_replace('/[\x80-\xFF]/', '?', $text);
        }
        $text = str_replace(["\r\n", "\r", "\x1A"], ["\n", "\n", ''], $text);
        $text = preg_replace_callback('/^[ \t]*\t/m', static function ($m) {   // leading tabs -> 4-col stops
            $r = '';
            foreach (str_split($m[0]) as $c) $r .= $c === "\t" ? str_repeat(' ', 4 - strlen($r) % 4) : ' ';
            return $r;
        }, $text);

        $lines = $this->definitions(explode("\n", $text));

        if ($this->abbr) {
            $keys = array_map(static function ($k) { return preg_quote((string)$k, '/'); }, array_keys($this->abbr));
            usort($keys, static function ($a, $b) { return strlen($b) - strlen($a); });
            $this->abbrRe = '/(?<![\p{L}\p{N}_])(?:' . implode('|', $keys) . ')(?![\p{L}\p{N}_])/u';
        }

        $html = $this->blocks($lines);

        if ($this->used) {                                   // footnotes, in order of first use
            $li = [];
            for ($k = 0; $k < count($this->used); $k++) {    // notes may reference further notes
                $key = (string)array_keys($this->used)[$k];
                $id = $this->attr($key);
                $h = $this->blocks($this->notes[$key]);
                $back = ' <a href="#fnref:' . $id . '" class="footnote-backref">&#8617;&#xFE0E;</a>';
                $h = substr($h, -4) === '</p>' ? substr($h, 0, -4) . $back . '</p>' : $h . $back;
                $li[] = '<li id="fn:' . $id . '">' . $h . '</li>';
            }
            $html .= "\n<div class=\"footnotes\">\n<hr />\n<ol>\n" . implode("\n", $li) . "\n</ol>\n</div>";
        }
        return $html . "\n";
    }

    /* ---------------------------------------------------- definitions ---- */

    /** Pull out [ref]: url "title", [^note]: text and *[ABBR]: text lines. */
    private function definitions(array $L): array
    {
        $out = [];
        $fence = '';
        for ($i = 0, $n = count($L); $i < $n; $i++) {
            $l = $L[$i];
            if ($fence !== '') {                                  // don't look inside fenced code
                if (preg_match($fence, $l)) $fence = '';
                $out[] = $l;
                continue;
            }
            if (preg_match(self::FENCE, $l, $m)) {
                $fence = '/^ {0,3}' . $m[2][0] . '{' . strlen($m[2]) . ',}[ \t]*$/';
                $out[] = $l;
            } elseif (preg_match('/^ {0,3}\[\^([^\]]+)\]:[ \t]?(.*)$/', $l, $m)) {
                $note = [$m[2]];
                while ($i + 1 < $n) {
                    $x = $L[$i + 1];
                    if (trim($x) === '') {                            // blank: continue only if indented text follows
                        for ($j = $i + 1; $j < $n && trim($L[$j]) === ''; $j++);
                        if ($j < $n && strncmp($L[$j], '    ', 4) === 0) {
                            for (; $i + 1 < $j; $i++) $note[] = '';
                            continue;
                        }
                        break;
                    }
                    if (strncmp($x, '    ', 4) === 0) $note[] = substr($x, 4);
                    elseif (trim(end($note)) !== '' && !preg_match('/^ {0,3}\*?\[[^\]]+\]:/', $x)) $note[] = $x;
                    else break;
                    $i++;
                }
                $this->notes[$this->key($m[1])] = $note;
            } elseif (preg_match('/^ {0,3}\*\[([^\]]+)\]:[ \t]*(.*)$/', $l, $m)) {
                $this->abbr[htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8')] = trim($m[2]);
            } elseif (preg_match('/^ {0,3}\[([^\]]+)\]:[ \t]*<?([^\s>]+)>?(?:[ \t]+(?:"(.*)"|\'(.*)\'|\((.*)\)))?(?:[ \t]+\{([#.][^}]*)\})?[ \t]*$/', $l, $m)) {
                $title = ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? '');
                if ($title === '' && $i + 1 < $n && preg_match('/^[ \t]+(?:"(.*)"|\'(.*)\'|\((.*)\))[ \t]*$/', $L[$i + 1], $t)) {
                    $title = ($t[1] ?? '') . ($t[2] ?? '') . ($t[3] ?? '');
                    $i++;
                }
                $this->refs[$this->key($m[1])] = [$m[2], $title, $m[6] ?? ''];
            } else {
                $out[] = $l;
            }
        }
        return $out;
    }

    /* --------------------------------------------------------- blocks ---- */

    private function blocks(array $L, bool $tight = false): string
    {
        $out = [];
        $n = count($L);
        $i = 0;
        while ($i < $n) {
            $l = $L[$i];
            if (trim($l) === '') { $i++; continue; }

            if (preg_match(self::FENCE, $l, $m)) {                                   // ``` fenced code
                $close = '/^ {0,3}' . $m[2][0] . '{' . strlen($m[2]) . ',}[ \t]*$/';
                $strip = '/^ {0,' . strlen($m[1]) . '}/';
                $code = [];
                for ($i++; $i < $n && !preg_match($close, $L[$i]); $i++) $code[] = preg_replace($strip, '', $L[$i]);
                $i++;
                $out[] = '<pre><code' . $this->fenceAttr($m[3]) . '>' . $this->esc($code ? implode("\n", $code) . "\n" : '') . '</code></pre>';
            } elseif (strncmp($l, '    ', 4) === 0) {                                // indented code
                $code = [];
                for (; $i < $n && (strncmp($L[$i], '    ', 4) === 0 || trim($L[$i]) === ''); $i++) $code[] = (string)substr($L[$i], 4);
                while (trim(end($code)) === '') array_pop($code);
                $out[] = '<pre><code>' . $this->esc(implode("\n", $code) . "\n") . '</code></pre>';
            } elseif (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t]*$/', $l, $m)) { // # ATX heading
                $out[] = $this->heading(strlen($m[1]), $m[2] ?? '', true);
                $i++;
            } elseif (preg_match(self::HR, $l)) {                                      // ---
                $out[] = '<hr />';
                $i++;
            } elseif (preg_match('/^ {0,3}>/', $l)) {                                   // > quote
                $q = [];
                for (; $i < $n; $i++) {
                    if (preg_match('/^ {0,3}> ?(.*)$/', $L[$i], $m)) $q[] = $m[1];
                    elseif (trim($L[$i]) !== '' && trim(end($q)) !== '' && !$this->startsBlock($L[$i])) $q[] = $L[$i];
                    else break;
                }
                if ($q && preg_match('/^[ \t]*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*$/i', $q[0], $a)) {
                    $type = strtolower($a[1]);                                         // > [!NOTE] alert (GFM)
                    $body = $this->blocks(array_slice($q, 1));
                    $out[] = '<div class="markdown-alert markdown-alert-' . $type . "\">\n"
                        . '<p class="markdown-alert-title">' . ucfirst($type) . "</p>\n"
                        . ($body === '' ? '' : "$body\n") . '</div>';
                } else {
                    $out[] = "<blockquote>\n" . $this->blocks($q) . "\n</blockquote>";
                }
            } elseif (preg_match(self::LI, $l)) {                                       // - list / 1. list
                $out[] = $this->listBlock($L, $i);
            } elseif (preg_match('/^ {0,3}<(?:(!--)|(\/?)(' . self::TAGS . ')(?=[\s\/>]|$))/i', $l, $m)) {
                $out[] = $this->htmlBlock($L, $i, $m);                                  // <div> HTML block
            } elseif (preg_match('/^ {0,3}<\/?[A-Za-z][A-Za-z0-9-]*(?:\s[^<>]*)?>[ \t]*$/', $l)) {
                for ($q = []; $i < $n && trim($L[$i]) !== ''; $i++) $q[] = $L[$i];     // <custom> tag alone on a line
                $out[] = implode("\n", $q);
            } elseif ($this->isTable($L, $i)) {                                         // | table |
                $out[] = $this->table($L, $i);
            } elseif ($this->isDl($L, $i)) {                                            // term / : definition
                $out[] = $this->dl($L, $i);
            } else {                                                                    // paragraph / setext heading
                $p = [];
                for (; $i < $n; $i++) {
                    $x = $L[$i];
                    if (trim($x) === '' || ($p && ($this->startsBlock($x) || $this->isTable($L, $i)))) break;
                    if ($i + 1 < $n && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $L[$i + 1], $m)) {
                        if ($p) $out[] = $this->para($p, $tight);
                        $out[] = $this->heading($m[1][0] === '=' ? 1 : 2, trim($x));
                        $i += 2;
                        continue 2;
                    }
                    $p[] = $x;
                }
                $out[] = $this->para($p, $tight);
            }
        }
        return implode("\n", $out);
    }

    /** Can this line interrupt a paragraph? */
    private function startsBlock(string $x): bool
    {
        return (bool)preg_match('/^ {0,3}(?:`{3,}|~{3,}|#{1,6}(?:[ \t]|$)|>|[*+-][ \t]+\S|1[.)][ \t]+\S|([-*_])(?:[ \t]*\1){2,}[ \t]*$|<(?:!--|\/?(?:' . self::TAGS . ')(?:[\s\/>]|$)))/i', $x);
    }

    private function para(array $p, bool $tight): string
    {
        $h = $this->inline(trim(implode("\n", $p)));
        return $tight ? $h : "<p>$h</p>";
    }

    private function heading(int $lvl, string $t, bool $atx = false): string
    {
        $spec = '';
        if (preg_match('/^(.*?)[ \t]*\{([#.][^}]*)\}$/', $t, $m)) { $t = $m[1]; $spec = $m[2]; }
        if ($atx) $t = preg_replace('/(?:^|[ \t]+)#+$/', '', $t);
        $h = $this->inline(trim($t));
        if (!$this->anchors) return "<h$lvl" . $this->attrs($spec) . ">$h</h$lvl>";

        if (preg_match('/(?:^|\s)#(\S+)/', $spec, $m)) $this->ids[$m[1]] = true;   // explicit {#id} wins
        else $spec .= ' #' . $this->slug($h);
        $attr = $this->attrs($spec);
        preg_match('/ id="([^"]*)"/', $attr, $m);
        return "<h$lvl$attr>" . '<a class="anchor" href="#' . $m[1] . '" aria-hidden="true" tabindex="-1"></a>' . "$h</h$lvl>";
    }

    /** GitHub-style unique heading id: lowercase, punctuation dropped, spaces become "-". */
    private function slug(string $html): string
    {
        $s = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = str_replace(' ', '-', preg_replace('/[^\p{L}\p{M}\p{N}_\- ]+/u', '', trim($s)));
        if ($s === '') $s = 'section';
        for ($id = $s, $k = 1; isset($this->ids[$id]); $k++) $id = "$s-$k";
        $this->ids[$id] = true;
        return $id;
    }

    private function listBlock(array $L, int &$i): string
    {
        $n = count($L);
        preg_match(self::LI, $L[$i], $m);
        $ol = $m[3] !== '';
        $start = $ol ? (int)$m[3] : 1;
        $base = strlen($m[1]);
        $items = [];
        $c = -1;
        $ind = 0;
        $loose = $blank = false;
        $blanks = 0;

        for (; $i < $n; $i++) {
            $l = $L[$i];
            if (trim($l) === '') { $blank = true; $blanks++; $items[$c][] = ''; continue; }
            $sp = strspn($l, ' ');
            if ($c >= 0 && ($sp >= $ind || ($sp > $base + 1 && preg_match(self::LI, $l)))) {
                $items[$c][] = substr($l, min($sp, $ind));                               // continuation / nested
            } elseif (preg_match(self::LI, $l, $m) && ($m[3] !== '') === $ol && !preg_match(self::HR, $l)) {
                if ($blanks > 1) break;                                                  // 2 blank lines end a list
                $w = strlen($m[4]);
                if ($w === 0 || $w > 4) $w = 1;                                          // "-     code" => indented code
                $ind = strlen($m[1]) + strlen($m[2]) + $w;
                $items[++$c] = [(string)substr($l, $ind)];
            } elseif (!$blank && !$this->startsBlock($l)) {
                $items[$c][] = ltrim($l);                                                // lazy continuation
            } else {
                break;
            }
            if ($blank) $loose = true;
            $blank = false;
            $blanks = 0;
        }

        $html = [];
        foreach ($items as $it) {
            $task = '';
            if (preg_match('/^\[([ xX])\][ \t]+/', $it[0], $t)) {                       // - [x] task (GFM)
                $task = '<input type="checkbox" disabled' . ($t[1] === ' ' ? '' : ' checked') . ' /> ';
                $it[0] = substr($it[0], strlen($t[0]));
            }
            $h = $this->blocks($it, !$loose);
            if ($task !== '') $h = strncmp($h, '<p>', 3) === 0 ? '<p>' . $task . substr($h, 3) : $task . $h;
            $html[] = '<li>' . $h . '</li>';
        }
        $tag = $ol ? 'ol' : 'ul';
        return '<' . $tag . ($ol && $start !== 1 ? ' start="' . $start . '"' : '') . ">\n" . implode("\n", $html) . "\n</$tag>";
    }

    private function htmlBlock(array $L, int &$i, array $m): string
    {
        $n = count($L);
        $buf = [];
        if ($m[1] === '!--') {                                                          // <!-- comment -->
            while ($i < $n) { $buf[] = $L[$i]; if (strpos($L[$i++], '-->') !== false) break; }
        } elseif ($m[2] === '/' || in_array(strtolower($m[3]), ['hr', 'link'], true)) { // until blank line
            while ($i < $n && trim($L[$i]) !== '') $buf[] = $L[$i++];
        } else {                                                                        // until the tag closes
            $tag = strtolower($m[3]);
            $depth = 0;
            while ($i < $n) {
                $x = $L[$i++];
                $buf[] = $x;
                $depth += preg_match_all('/<' . $tag . '(?=[\s>]|$)(?:[^>]*[^\/])?>/i', $x)
                        - preg_match_all('/<\/' . $tag . '\s*>/i', $x);
                if ($depth <= 0) break;
            }
        }
        return implode("\n", $buf);
    }

    private function isTable(array $L, int $i): bool
    {
        return isset($L[$i + 1]) && strpos($L[$i], '|') !== false && strpos($L[$i + 1], '|') !== false
            && preg_match(self::SEP, $L[$i + 1]);
    }

    private function table(array $L, int &$i): string
    {
        $head = $this->cells($L[$i]);
        $cols = count($head);
        $align = [];
        foreach ($this->cells($L[$i + 1]) as $s) {
            $s = trim($s);
            $a = $s !== '' && $s[0] === ':' ? (substr($s, -1) === ':' ? 'center' : 'left') : (substr($s, -1) === ':' ? 'right' : '');
            $align[] = $a === '' ? '' : ' style="text-align: ' . $a . '"';
        }
        $row = function (array $cells, string $tag) use ($cols, $align) {
            $r = '<tr>';
            for ($k = 0; $k < $cols; $k++) $r .= "<$tag" . ($align[$k] ?? '') . '>' . $this->inline(trim($cells[$k] ?? '')) . "</$tag>";
            return $r . "</tr>\n";
        };
        $h = "<table>\n<thead>\n" . $row($head, 'th') . "</thead>\n";
        $body = '';
        for ($i += 2, $n = count($L); $i < $n && trim($L[$i]) !== '' && strpos($L[$i], '|') !== false; $i++) {
            $body .= $row($this->cells($L[$i]), 'td');
        }
        return $h . ($body === '' ? '' : "<tbody>\n$body</tbody>\n") . '</table>';
    }

    /** Split a table row on "|", ignoring escaped pipes and pipes inside `code`. */
    private function cells(string $row): array
    {
        $row = trim($row);
        if (strncmp($row, '|', 1) === 0) $row = substr($row, 1);
        if (substr($row, -1) === '|' && substr($row, -2) !== '\\|') $row = substr($row, 0, -1);
        return str_replace('\\|', '|', preg_split('/(`+).*?(?<!`)\1(?!`)(*SKIP)(*FAIL)|\\\\.(*SKIP)(*FAIL)|\|/', $row));
    }

    private function isDl(array $L, int $i): bool
    {
        $n = count($L);
        for ($k = $i; $k < $n && trim($L[$k]) !== '' && !preg_match(self::DD, $L[$k]); $k++);
        if ($k === $i) return false;
        if ($k < $n && trim($L[$k]) === '') $k++;
        return $k < $n && preg_match(self::DD, $L[$k]) === 1;
    }

    private function dl(array $L, int &$i): string
    {
        $n = count($L);
        $h = "<dl>\n";
        while (true) {
            while (trim($L[$i]) !== '' && !preg_match(self::DD, $L[$i])) $h .= '<dt>' . $this->inline(trim($L[$i++])) . "</dt>\n";
            $loose = false;
            if (trim($L[$i]) === '') { $loose = true; $i++; }
            while ($i < $n && preg_match(self::DD, $L[$i], $m)) {
                $dd = [substr($L[$i], strlen($m[0]))];
                $ddLoose = $loose;
                for ($i++; $i < $n;) {
                    if (trim($L[$i]) !== '') {
                        if (preg_match(self::DD, $L[$i])) break;
                        $dd[] = preg_replace('/^ {1,4}/', '', $L[$i++]);
                        continue;
                    }
                    for ($j = $i; $j < $n && trim($L[$j]) === ''; $j++);
                    if ($j < $n && strncmp($L[$j], '    ', 4) === 0) {
                        $ddLoose = true;
                        for (; $i < $j; $i++) $dd[] = '';
                        continue;
                    }
                    if ($j < $n && preg_match(self::DD, $L[$j])) { $ddLoose = $loose = true; $i = $j; }
                    break;
                }
                $h .= '<dd>' . $this->blocks($dd, !$ddLoose) . "</dd>\n";
            }
            for ($j = $i; $j < $n && trim($L[$j]) === ''; $j++);
            if ($j < $n && $this->isDl($L, $j)) { $i = $j; continue; }
            return $h . '</dl>';
        }
    }

    private function fenceAttr(string $info): string
    {
        $attr = '';
        if (preg_match('/\{([^}]*)\}$/', $info, $m)) { $attr = $m[1]; $info = substr($info, 0, -strlen($m[0])); }
        $lang = preg_split('/\s+/', trim($info))[0];
        if ($lang !== '' && ($lang[0] === '.' || $lang[0] === '#')) { $attr = "$info $attr"; $lang = ''; }
        return $this->attrs($attr, $lang === '' ? '' : 'language-' . $lang);
    }

    /** Markdown Extra special attributes: {#id .class1 .class2} */
    private function attrs(string $s, string $class = ''): string
    {
        $id = '';
        $cls = $class === '' ? [] : [$class];
        foreach (preg_split('/\s+/', trim($s)) as $a) {
            if ($a === '') continue;
            if ($a[0] === '#') $id = substr($a, 1);
            elseif ($a[0] === '.') $cls[] = substr($a, 1);
        }
        return ($id === '' ? '' : ' id="' . $this->attr($id) . '"') . ($cls ? ' class="' . $this->attr(implode(' ', $cls)) . '"' : '');
    }

    /* --------------------------------------------------------- inline ---- */

    private function inline(string $s): string
    {
        $s = preg_replace_callback(self::INLINE, [$this, 'token'], $s, -1, $cnt, PREG_UNMATCHED_AS_NULL) ?? $s;
        $s = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8', false);   // keeps existing &entities;

        if ($this->abbrRe !== '') {
            $s = preg_replace_callback($this->abbrRe, function ($m) {
                return $this->keep('<abbr title="' . $this->attr($this->abbr[$m[0]]) . '">' . $m[0] . '</abbr>');
            }, $s);
        }

        $s = $this->emph($s);
        $s = preg_replace('/ {2,}\n/', "<br />\n", $s);
        if ($this->breaks) $s = preg_replace('/(?<!<br \/>)\n/', "<br />\n", $s);

        return preg_replace_callback('/\x1A(\d+)\x1A/', function ($m) { return $this->tok[(int)$m[1]]; }, $s);
    }

    /** *em*, **strong**, ***both***, _em_, __strong__ (not inside words), ~del~ / ~~del~~ */
    private function emph(string $s): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n;) {
            $k = strcspn($s, '*_~', $i);
            $out .= substr($s, $i, $k);
            if (($i += $k) >= $n) break;
            $c = $s[$i];
            $prev = $i > 0 ? $s[$i - 1] : ' ';
            if ($c === '_' && (ctype_alnum($prev) || ord($prev) > 127)) {         // snake_case_word
                $k = strspn($s, '_', $i);
                $out .= substr($s, $i, $k);
                $i += $k;
                continue;
            }
            $double = ($s[$i + 1] ?? '') === $c;
            if ($c === '~') {                                                       // ~ or ~~, closed by the same count
                $run = strspn($s, '~', $i);
                $re = $run > 2 ? '/(?!)/' : ($double ? '/\G~~(?=[^\s~])(.+?)(?<=[^\s~])~~(?!~)/s'
                                                     : '/\G~(?=[^\s~])(.+?)(?<=[^\s~])~(?!~)/s');
            } elseif ($c === '*') $re = $double ? '/\G\*\*(?=\S)((?>[^*]|\*[^*]*\*)+?)(?<=\S)\*\*/s'
                                              : '/\G\*(?=\S)((?>[^*]|\*\*[^*]+?\*\*)+?)(?<=\S)\*(?!\*[^*])/s';
            else $re = $double ? '/\G__(?=\S)((?>[^_]|_[^_]*_)+?)(?<=\S)__(?![\p{L}\p{N}])/su'
                               : '/\G_(?=\S)((?>[^_]|__[^_]*__)+?)(?<=\S)_(?![\p{L}\p{N}_])/su';
            if (preg_match($re, $s, $m, 0, $i) && trim($m[1], '*_~ ') !== '') {
                $tag = $c === '~' ? 'del' : ($double ? 'strong' : 'em');
                $out .= "<$tag>" . $this->emph($m[1]) . "</$tag>";
                $i += strlen($m[0]);
            } elseif ($c === '~') {                                                  // unmatched run stays literal
                $out .= str_repeat('~', $run);
                $i += $run;
            } else {
                $out .= $c;
                $i++;
            }
        }
        return $out;
    }

    /** Store finished HTML and return an opaque placeholder for it. */
    private function keep(string $html): string
    {
        $this->tok[] = $html;
        return "\x1A" . (count($this->tok) - 1) . "\x1A";
    }

    private function token(array $m): string
    {
        if (isset($m['esc'])) return $this->keep($m['esc'] === "\n" ? "<br />\n" : $this->esc($m['esc']));
        if (isset($m['code'])) {
            $c = trim($m['code']);
            return $this->keep('<code>' . $this->esc($c) . (preg_match(self::COLOR, $c)                  // `#0969da` swatch (GFM)
                ? '<span class="color-swatch" style="background-color:' . $this->attr($c) . '"></span>' : '') . '</code>');
        }
        if ($this->inLink && (isset($m['auto']) || isset($m['www']) || isset($m['bmail']) || (isset($m['txt']) && $m['img'] !== '!'))) {
            return $this->keep($this->esc($m[0]));                       // no links inside links
        }
        if (isset($m['auto'])) return $this->keep('<a href="' . $this->attr($m['auto']) . '">' . $this->esc($m['auto']) . '</a>');
        if (isset($m['mail'])) return $this->keep('<a href="mailto:' . $this->attr($m['mail']) . '">' . $this->esc($m['mail']) . '</a>');
        if (isset($m['bmail'])) return $this->keep('<a href="mailto:' . $this->attr($m['bmail']) . '">' . $this->esc($m['bmail']) . '</a>');
        if (isset($m['html'])) return $this->keep($m['html']);
        if (isset($m['www'])) {
            $u = $m['www'];
            return $this->keep('<a href="' . $this->attr(strncmp($u, 'www.', 4) === 0 ? "http://$u" : $u) . '">' . $this->esc($u) . '</a>');
        }
        if (isset($m['fn'])) {
            $k = $this->key($m['fn']);
            if (!isset($this->notes[$k])) return $this->keep($this->esc($m[0]));
            if (!isset($this->used[$k])) $this->used[$k] = [count($this->used) + 1, 0];
            $num = $this->used[$k][0];
            $nth = ++$this->used[$k][1];
            $id = $this->attr($k);
            return $this->keep('<sup id="fnref' . ($nth > 1 ? $nth : '') . ':' . $id . '"><a href="#fn:' . $id . '" class="footnote-ref">' . $num . '</a></sup>');
        }

        // links and images
        $img = $m['img'] === '!';
        $text = $m['txt'];
        if (isset($m['url'])) {
            $url = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $m['url']);
            $title = $m['title'] ?? $m['title2'] ?? '';
        } else {
            $ref = $this->refs[$this->key(isset($m['ref']) && trim($m['ref']) !== '' ? $m['ref'] : $text)] ?? null;
            if ($ref === null) {                                         // not a link after all
                return $this->keep(($img ? '!' : '') . '[' . $this->inline($text) . ']'
                    . (isset($m['ref']) ? '[' . $this->esc($m['ref']) . ']' : '')
                    . (isset($m['attr']) ? $this->esc('{' . $m['attr'] . '}') : ''));
            }
            [$url, $title, $refAttr] = $ref;
            if (!isset($m['attr']) && $refAttr !== '') $m['attr'] = $refAttr;
        }
        $a = ($title === '' ? '' : ' title="' . $this->attr($title) . '"') . (isset($m['attr']) ? $this->attrs($m['attr']) : '');
        $url = $this->attr(str_replace(' ', '%20', $url));
        return $this->keep($img
            ? '<img src="' . $url . '" alt="' . $this->attr($text) . '"' . $a . ' />'
            : '<a href="' . $url . '"' . $a . '>' . $this->linkText($text) . '</a>');
    }

    private function linkText(string $text): string
    {
        $this->inLink = true;
        $h = $this->inline($text);
        $this->inLink = false;
        return $h;
    }

    /* -------------------------------------------------------- helpers ---- */

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    private function key(string $k): string
    {
        $k = preg_replace('/\s+/', ' ', trim($k));
        return function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k);
    }
}

/* ------------------------------------------------------------------------- */
/*  Page template                                                            */
/* ------------------------------------------------------------------------- */

function render_page(string $file): string
{
    $md = new Markdown();
    $md->breaks = MD_BREAKS;
    $md->anchors = MD_ANCHORS;
    $body = $md->parse((string)file_get_contents($file));

    $title = preg_match('~<h1[^>]*>(.*?)</h1>~s', $body, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
    $title = htmlspecialchars($title === '' || $title === SITE_NAME ? SITE_NAME : $title . ' · ' . SITE_NAME, ENT_QUOTES, 'UTF-8');
    $lang = SITE_LANG;

    return <<<HTML
<!DOCTYPE html>
<html lang="$lang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>
:root{--fg:#1f2328;--bg:#fff;--muted:#59636e;--line:#d1d9e0;--code:#f6f8fa;--link:#0969da}
@media (prefers-color-scheme:dark){:root{--fg:#e6edf3;--bg:#0d1117;--muted:#9198a1;--line:#3d444d;--code:#151b23;--link:#4493f8}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:48rem;margin:0 auto;padding:2rem 1rem 4rem;overflow-wrap:break-word}
a{color:var(--link)}
h1,h2,h3,h4,h5,h6{line-height:1.25;margin:1.5em 0 .6em}
h1,h2{padding-bottom:.3em;border-bottom:1px solid var(--line)}
[id]{scroll-margin-top:1rem}
.anchor{float:left;width:1em;margin-left:-1em;color:var(--muted);text-decoration:none;opacity:0}
.anchor::before{content:"#"}
:hover>.anchor,.anchor:focus,:target>.anchor{opacity:1}
img,video{max-width:100%;height:auto}
code,pre{font:.875em/1.45 ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace;background:var(--code);border-radius:6px}
code{padding:.15em .35em}
pre{padding:1em;overflow:auto}
pre code{padding:0;font-size:1em;background:none}
blockquote{margin:0 0 1em;padding:0 1em;color:var(--muted);border-left:.25em solid var(--line)}
table{display:block;max-width:100%;overflow:auto;border-collapse:collapse;margin:0 0 1em}
th,td{padding:.4em .8em;border:1px solid var(--line)}
hr{height:0;margin:2em 0;border:0;border-top:1px solid var(--line)}
dt{font-weight:600}
dd{margin:0 0 .5em 1.5em}
.footnotes{font-size:.875em;color:var(--muted)}
.color-swatch{display:inline-block;width:.75em;height:.75em;margin-left:.4em;border:1px solid var(--line);border-radius:50%;vertical-align:-.05em}
.markdown-alert{margin:0 0 1em;padding:.5em 1em;border-left:.25em solid var(--alert)}
.markdown-alert>:last-child{margin-bottom:0}
.markdown-alert-title{margin:0 0 .25em;font-weight:600;color:var(--alert)}
.markdown-alert-note{--alert:#0969da}.markdown-alert-tip{--alert:#1a7f37}.markdown-alert-important{--alert:#8250df}
.markdown-alert-warning{--alert:#9a6700}.markdown-alert-caution{--alert:#d1242f}
@media (prefers-color-scheme:dark){.markdown-alert-note{--alert:#4493f8}.markdown-alert-tip{--alert:#3fb950}
.markdown-alert-important{--alert:#ab7df8}.markdown-alert-warning{--alert:#d29922}.markdown-alert-caution{--alert:#f85149}}
</style>
</head>
<body>
<main>
$body</main>
</body>
</html>

HTML;
}

/* ------------------------------------------------------------------------- */
/*  Router: URL -> .md file -> cached HTML                                   */
/* ------------------------------------------------------------------------- */

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
if ($base !== '' && ($path === $base || strncmp($path, $base . '/', strlen($base) + 1) === 0)) $path = substr($path, strlen($base));
$slash = substr($path, -1) === '/';
$path = trim($path, '/');

$file = null;
$isDir = false;
if (!preg_match('~(^|/)\.|\\\\|\0~', $path)) {                  // no dotfiles, "..", backslashes, NULs
    $p = preg_replace('~\.md$~i', '', $path);
    $dir = $p === '' ? __DIR__ : __DIR__ . '/' . $p;
    $try = $p === '' ? [] : [$dir . '.md' => false];
    $try += [$dir . '/index.md' => true, $dir . '/README.md' => true];
    foreach ($try as $f => $d) {
        if (is_file($f)) { $file = $f; $isDir = $d; break; }
    }
    if ($file !== null && $p !== '' && $isDir !== $slash) {     // canonical URL: /docs/ and /about
        $q = $_SERVER['QUERY_STRING'] ?? '';
        $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', $p))) . ($isDir ? '/' : '');
        header('Location: ' . $url . ($q === '' ? '' : "?$q"), true, 301);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
if ($file === null) {
    http_response_code(404);
    $file = __DIR__ . '/404.md';
    if (!is_file($file)) exit('<!DOCTYPE html><title>404 Not Found</title><h1>404 Not Found</h1>');
} else {                                                        // let browsers revalidate cheaply
    $mtime = max(filemtime($file), filemtime(__FILE__));
    $etag = '"' . dechex($mtime) . '-' . dechex(filesize($file)) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: no-cache');
    $inm = str_replace('-gzip', '', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' ? strpos($inm, $etag) !== false : strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') >= $mtime) {
        http_response_code(304);
        exit;
    }
}

$cache = CACHE_DIR === '' ? '' : CACHE_DIR . '/' . md5($file) . '.html';
if ($cache !== '' && is_file($cache) && filemtime($cache) > max(filemtime($file), filemtime(__FILE__))) {
    readfile($cache);
    exit;
}
$html = render_page($file);
if ($cache !== '' && (is_dir(CACHE_DIR) || @mkdir(CACHE_DIR, 0775, true))) {
    $tmp = $cache . '.' . getmypid();
    if (@file_put_contents($tmp, $html) !== false) @rename($tmp, $cache);
}
echo $html;
