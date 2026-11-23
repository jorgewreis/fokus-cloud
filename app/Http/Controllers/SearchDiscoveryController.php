<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SearchDiscoveryController extends Controller
{
    public function robots(Request $request)
    {
        $origin = $request->getHost() === 'styles.fokuscloud.com.br'
            ? 'https://styles.fokuscloud.com.br'
            : 'https://www.fokuscloud.com.br';

        return response(file_get_contents(resource_path('seo/robots.txt'))."\nSitemap: {$origin}/sitemap.xml\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(Request $request)
    {
        $host = $request->getHost() === 'styles.fokuscloud.com.br'
            ? 'styles.fokuscloud.com.br'
            : 'www.fokuscloud.com.br';
        $pages = json_decode(file_get_contents(resource_path('seo/pages.json')), true, flags: JSON_THROW_ON_ERROR);
        $lastmodPath = resource_path('seo/lastmod.json');
        $lastmods = is_file($lastmodPath)
            ? json_decode(file_get_contents($lastmodPath), true, flags: JSON_THROW_ON_ERROR)
            : [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($pages as $page) {
            if (parse_url($page['url'], PHP_URL_HOST) !== $host) {
                continue;
            }
            $loc = htmlspecialchars($page['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lastmod = $lastmods[$page['file']] ?? null;
            $xml .= '    <url><loc>'.$loc.'</loc>';
            if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod)) {
                $xml .= '<lastmod>'.$lastmod.'</lastmod>';
            }
            $xml .= "</url>\n";
        }

        return response($xml."</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
