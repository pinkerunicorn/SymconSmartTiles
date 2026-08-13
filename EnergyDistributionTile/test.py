import json
import html

data = {
    'house': {
        'name': 'Haus',
        'consumption': 0.0,
        'cost': 0.0,
    },
    'consumers': []
}
jsondata = json.dumps(data)
escaped = html.escape(jsondata, quote=True)
print(escaped)
