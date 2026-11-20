<?php

namespace App\Services\SiteAudit;

/**
 * A parsed robots.txt: the rules of the group for our user agent (else `*`)
 * and the Sitemap: lines. Matching follows Google's rules: the longest
 * matching rule wins, Allow wins a tie, `*` matches anything and `$` ends
 * the path. A missing or unreadable robots.txt allows everything.
 */
final class RobotsTxt
{
    /**
     * @param  array<string, list<array{0: bool, 1: string}>>  $groups  agent => [[allow?, pattern]]
     * @param  list<string>  $sitemaps
     */
    private function __construct(private readonly array $groups, public readonly array $sitemaps, public readonly bool $found) {}

    public static function allowAll(): self
    {
        return new self([], [], false);
    }

    public static function parse(string $body): self
    {
        $groups = [];
        $sitemaps = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $value;
                }

                continue;
            }

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                $groups[strtolower($value)] ??= [];

                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($agents as $agent) {
                    // An empty Disallow allows everything: no rule.
                    if ($value !== '') {
                        $groups[$agent][] = [$field === 'allow', $value];
                    }
                }
            }
        }

        return new self($groups, array_values(array_unique($sitemaps)), true);
    }

    /** Whether $agent (e.g. "indlelabot", "googlebot") may fetch this path (+ query). */
    public function allows(string $pathAndQuery, string $agent): bool
    {
        $rules = $this->rulesFor(strtolower($agent));
        $best = null;

        foreach ($rules as [$allow, $pattern]) {
            if (! $this->matches($pattern, $pathAndQuery)) {
                continue;
            }
            $length = strlen($pattern);
            if ($best === null || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best === null || $best[0];
    }

    /** @return list<array{0: bool, 1: string}> */
    private function rulesFor(string $agent): array
    {
        foreach ($this->groups as $name => $rules) {
            if ($name !== '*' && $name !== '' && str_contains($agent, $name)) {
                return $rules;
            }
        }

        return $this->groups['*'] ?? [];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote($anchored ? substr($pattern, 0, -1) : $pattern, '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
