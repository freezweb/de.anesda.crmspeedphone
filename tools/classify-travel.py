#!/usr/bin/env python3
"""Einmalige, konservative PLZ-/Ortseinschätzung; keine Kundenanschriften an Dritte."""
import argparse
import collections
import io
import json
import math
from pathlib import Path
import re
import time
import unicodedata
import urllib.request
import zipfile


def normalize(value):
    value = unicodedata.normalize('NFKD', value.lower().replace('ß', 'ss'))
    value = ''.join(character for character in value if not unicodedata.combining(character))
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', value).split())


def classify(durations, limit=60, margin=10):
    if not durations or any(value is None for value in durations):
        return 'unverified'
    if max(durations) <= (limit - margin) * 60:
        return 'within_range'
    if min(durations) > (limit + margin) * 60:
        return 'too_far'
    return 'borderline'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input')
    parser.add_argument('output')
    parser.add_argument('--origin', required=True, help='Längengrad,Breitengrad')
    parser.add_argument('--limit', type=int, default=60)
    parser.add_argument('--margin', type=int, default=10)
    parser.add_argument('--include-city', action='append', default=[], help='Ort unabhängig vom Zeitlimit freigeben')
    args = parser.parse_args()
    out = Path(args.output)
    cache_file = out.with_suffix('.routes-cache.json')
    dataset_file = out.with_suffix('.geonames-DE.zip')
    headers = {'User-Agent': 'Anesda-Nord-SpeedPhone/1.0 (info@anesda-nord.de)'}

    def request(url):
        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req, timeout=35) as response:
            return response.read()

    if not dataset_file.exists():
        dataset_file.write_bytes(request('https://download.geonames.org/export/zip/DE.zip'))
    places = collections.defaultdict(list)
    cities = collections.defaultdict(list)
    with zipfile.ZipFile(dataset_file) as archive:
        for line in archive.read('DE.txt').decode('utf-8').splitlines():
            cells = line.split('\t')
            point = (float(cells[10]), float(cells[9]))
            name = normalize(cells[2])
            places[cells[1]].append((name, point))
            cities[name].append(point)
    locations = json.loads(Path(args.input).read_text(encoding='utf-8'))
    included_cities = {normalize(value) for value in args.include_city}
    selected = {}
    for row in locations:
        country = normalize(row['country'] or '')
        if country not in ('', 'de', 'deu', 'germany', 'deutschland', 'bundesrepublik deutschland'):
            selected[row['key']] = []
            continue
        postcode = row['postcode'].strip()
        city = normalize(row['city'])
        candidates = places.get(postcode, []) if re.fullmatch(r'\d{5}', postcode) else []
        points = [p for name, p in candidates if not city or name == city or name.startswith(city + ' ') or city.startswith(name + ' ')]
        if not postcode and city:
            points = cities.get(city, [])
            # Gleichnamige Orte in verschiedenen Regionen nicht zusammenwerfen.
            if points and (max(p[0] for p in points)-min(p[0] for p in points) > .25 or max(p[1] for p in points)-min(p[1] for p in points) > .15):
                points = []
        selected[row['key']] = sorted(set(points))
    all_points = sorted(set(p for points in selected.values() for p in points))
    def point_key(point):
        return ','.join(f'{value:.6f}' for value in point)
    cache = json.loads(cache_file.read_text()) if cache_file.exists() else {'origin': args.origin, 'durations': {}}
    if cache['origin'] != args.origin:
        raise RuntimeError('Der Routencache gehört zu einem anderen Abfahrtsort.')
    missing = [p for p in all_points if point_key(p) not in cache['durations']]
    batches = [missing[i:i+80] for i in range(0, len(missing), 80)]
    if len(batches) > 100:
        raise RuntimeError('Mehr als 100 Matrix-Anfragen: eigener Routingserver erforderlich.')
    print(f'Orte={len(locations)} Koordinaten={len(all_points)} Matrixanfragen={len(batches)}', flush=True)
    for index, batch in enumerate(batches):
        coordinates = args.origin + ';' + ';'.join(point_key(p) for p in batch)
        destinations = ';'.join(str(i) for i in range(1, len(batch)+1))
        url = 'https://routing.openstreetmap.de/routed-car/table/v1/driving/' + coordinates + '?annotations=duration&sources=0&destinations=' + destinations
        for attempt in range(3):
            try:
                result = json.loads(request(url))
                if result.get('code') != 'Ok':
                    raise RuntimeError('Routingserver lieferte keine Matrix.')
                break
            except Exception:
                if attempt == 2:
                    raise
                time.sleep(5)
        for point, duration, destination in zip(batch, result['durations'][0], result['destinations']):
            cache['durations'][point_key(point)] = duration if destination['distance'] <= 2000 else None
        cache_file.write_text(json.dumps(cache), encoding='utf-8')
        if index % 5 == 0 or index == len(batches)-1:
            print(f'Routenmatrix {index+1}/{len(batches)}', flush=True)
        time.sleep(1.1)
    assessments = {}
    for row in locations:
        values = [cache['durations'].get(point_key(p)) for p in selected[row['key']]]
        status = classify(values, args.limit, args.margin)
        valid = [v for v in values if v is not None]
        is_exception = normalize(row['city']) in included_cities and bool(valid)
        if is_exception:
            status = 'included_exception'
        assessments[row['key']] = {
            'status': status,
            'minutes': math.ceil(max(valid)/60) if valid else None,
            'note': (('Regional ausdrücklich einbezogen. ' if is_exception else '')
                    + 'PLZ-/Ortsschätzung, keine adressgenaue Route. '
                     f'Fahrzeitbereich {math.ceil(min(valid)/60)}–{math.ceil(max(valid)/60)} Min.; '
                     f'Grenzbereich ±{args.margin} Min. separat zur Prüfung. ' if valid else 'Ort nicht eindeutig bestimmbar oder Route fehlt. ')
                    + 'Quelle: GeoNames (CC BY 3.0), OpenStreetMap/OSRM (FOSSGIS). Ohne Live-Verkehr.',
        }
    out.write_text(json.dumps(assessments, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps(dict(collections.Counter(r['status'] for r in assessments.values()))), flush=True)


if __name__ == '__main__':
    main()
