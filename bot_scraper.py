#!/usr/bin/env python3
import sys, json, requests, re, sqlite3, os
from urllib.parse import urlparse, urljoin
from datetime import datetime

DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data', 'scraper.db')

def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def crawl_site(url, depth=2, max_pages=50):
    visited = set()
    to_visit = [(url, 0)]
    base_domain = urlparse(url).netloc
    results = []

    while to_visit and len(visited) < max_pages:
        page_url, d = to_visit.pop(0)
        if page_url in visited or d > depth:
            continue
        try:
            r = requests.get(page_url, timeout=10, headers={'User-Agent': 'Mozilla/5.0 (compatible; Bot/1.0)'}, allow_redirects=True)
            r.raise_for_status()
        except:
            visited.add(page_url)
            continue

        visited.add(page_url)
        content_type = r.headers.get('Content-Type', '')
        title = ''
        if 'text/html' in content_type:
            m = re.search(r'<title[^>]*>(.*?)</title>', r.text, re.IGNORECASE | re.DOTALL)
            if m:
                title = m.group(1).strip()[:200]

        results.append({
            'url': page_url,
            'title': title,
            'status': r.status_code,
            'size': len(r.content),
            'type': content_type.split(';')[0] if ';' in content_type else content_type,
        })

        if 'text/html' in content_type:
            for m in re.finditer(r'href=["\'](https?://[^"\']+)["\']', r.text):
                link = m.group(1)
                if urlparse(link).netloc == base_domain and link not in visited:
                    to_visit.append((link, d + 1))

    return results

def save_sites(conn, sites):
    cur = conn.cursor()
    for s in sites:
        domain = urlparse(s['url']).netloc
        cur.execute(
            "INSERT OR IGNORE INTO bot_sites (url, domain, title, description) VALUES (?, ?, ?, ?)",
            (s['url'], domain, s.get('title', ''), s.get('description', ''))
        )
    conn.commit()

def main():
    result = {'success': True, 'sites_count': 0, 'error': None}
    try:
        conn = get_db()
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*) FROM bot_sites")
        existing = cur.fetchone()[0]
        if existing > 1000:
            result['sites_count'] = existing
            result['message'] = f'يوجد بالفعل {existing} موقع في قاعدة البيانات'
            print(json.dumps(result, ensure_ascii=False))
            return

        start_urls = [
            'https://www.alexa.com/siteinfo',
            'https://moz.com/top500',
        ]
        for start_url in start_urls:
            try:
                sites = crawl_site(start_url, depth=1, max_pages=20)
                save_sites(conn, sites)
                result['sites_count'] += len(sites)
            except Exception as e:
                result['error'] = str(e)

        cur.execute("SELECT COUNT(*) FROM bot_sites")
        result['sites_count'] = cur.fetchone()[0]
        result['message'] = f'تم جمع {result["sites_count"]} موقع'
        conn.close()
    except Exception as e:
        result['success'] = False
        result['error'] = str(e)

    print(json.dumps(result, ensure_ascii=False))

if __name__ == '__main__':
    main()
