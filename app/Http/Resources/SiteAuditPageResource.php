<?php

namespace App\Http\Resources;

use App\Models\SiteAuditPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An audited URL. The list row carries issues_count; the detail adds the
 * redirect chain, headers and extras (issues and inlinks are added by the
 * controller).
 *
 * @mixin SiteAuditPage
 */
class SiteAuditPageResource extends JsonResource
{
    public bool $detail = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'depth' => $this->depth,
            'status_code' => $this->status_code,
            'final_url' => $this->final_url,
            'final_status_code' => $this->final_status_code,
            'content_type' => $this->content_type,
            'response_ms' => $this->response_ms,
            'title' => $this->title,
            'meta_description' => $this->meta_description,
            'h1' => $this->h1,
            'h1_count' => $this->h1_count,
            'canonical' => $this->canonical,
            'meta_robots' => $this->meta_robots,
            'is_indexable' => $this->is_indexable,
            'in_sitemap' => $this->in_sitemap,
            'inlinks_count' => $this->inlinks_count,
            'outlinks_count' => $this->outlinks_count,
            'word_count' => $this->word_count,
            'images_count' => $this->images_count,
            'images_missing_alt' => $this->images_missing_alt,
            'issues_count' => $this->whenCounted('issues'),
            $this->mergeWhen($this->detail, fn () => [
                'redirect_chain' => $this->redirect_chain,
                'x_robots_tag' => $this->x_robots_tag,
                'canonical_count' => $this->canonical_count,
                'fetch_error' => $this->fetch_error,
                'missing_alt_images' => $this->extras['missing_alt_srcs'] ?? [],
            ]),
        ];
    }
}
