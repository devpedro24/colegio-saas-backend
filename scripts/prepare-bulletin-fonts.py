# Development only: pip install fonttools[woff]. Web sources stay in the frontend.
import argparse
from pathlib import Path
import json
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.pens.recordingPen import DecomposingRecordingPen
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.pens.transformPen import TransformPen

root=Path(__file__).resolve().parents[1]/'resources/bulletin-editor/fonts'
parser=argparse.ArgumentParser(description='Prepare PDF faces from the matching frontend WOFF2 distribution. Not required at runtime.')
parser.add_argument('--web-fonts',type=Path,required=True,help='Path to public/bulletin-editor/assets/fonts in the frontend release')
parser.add_argument('--output',type=Path,default=root,help='Output directory; defaults to backend runtime fonts')
parser.add_argument('--family',action='append',help='Optional family filter for a development verification')
args=parser.parse_args()
fonts=json.loads((root/'catalog/manifest.json').read_text(encoding='utf-8'))
fonts.append({'key':'inter','family':'Inter'})
generated=[]
for entry in fonts:
    if args.family and entry['family'] not in args.family: continue
    source=args.web_fonts/'catalog'/entry['key']/'font.woff2' if entry['key']!='inter' else args.web_fonts/'inter-latin.woff2'
    target=args.output/'catalog'/entry['key'] if entry['key']!='inter' else args.output
    target.mkdir(parents=True,exist_ok=True)
    for weight,style in [(400,'Regular'),(700,'Bold')]:
        font=TTFont(source)
        font.flavor=None
        if 'fvar' in font:
            axes={a.axisTag:max(a.minValue,min(a.maxValue,weight)) if a.axisTag=='wght' else a.defaultValue for a in font['fvar'].axes}
            font=instantiateVariableFont(font,axes,inplace=True)
        family='School Bulletin '+entry['family']
        names={1:family,2:style,4:family+' '+style,6:family.replace(' ','')+'-'+style,16:family,17:style}
        for name in list(font['name'].names):
            if name.nameID in names: font['name'].setName(names[name.nameID],name.nameID,name.platformID,name.platEncID,name.langID)
        output=target/('pdf-'+style.lower()+'.ttf')
        font.save(output)
        font.close()
        generated.append(output)
    print(entry['family'])

for source in generated:
    font=TTFont(source)
    if 'glyf' not in font: continue
    glyphs=font.getGlyphSet(); transformed={}
    for name in font.getGlyphOrder():
        recording=DecomposingRecordingPen(glyphs);glyphs[name].draw(recording)
        pen=TTGlyphPen(None);recording.replay(TransformPen(pen,(1,0,.21256,1,0,0)))
        transformed[name]=pen.glyph()
    for name,glyph in transformed.items():font['glyf'][name]=glyph
    font['post'].italicAngle=-12
    font['head'].macStyle|=2
    font['OS/2'].fsSelection=(font['OS/2'].fsSelection|1)&~64
    for name in list(font['name'].names):
        if name.nameID in [2,4,6,17]:font['name'].setName(name.toUnicode()+' Italic',name.nameID,name.platformID,name.platEncID,name.langID)
    font.save(source.with_name(source.stem+'-italic.ttf'))
    font.close()
