#!/usr/bin/env python3
import sys, json, base64
try:
    import cloudscraper
    url = sys.argv[1]
    timeout = int(sys.argv[2]) if len(sys.argv) > 2 else 15
    scraper = cloudscraper.create_scraper(
        browser={'browser':'chrome','platform':'windows','desktop':True,'mobile':False}
    )
    r = scraper.get(url, timeout=timeout, allow_redirects=True)
    result = {
        'status': r.status_code,
        'body_b64': base64.b64encode(r.text.encode('utf-8')).decode('ascii'),
    }
except Exception as e:
    result = {'error': str(e)}
print(json.dumps(result))
