"""Development-only offline PDF glyph atlas. Requires fonttools and Pillow; OFL source is bundled."""
from pathlib import Path
from io import BytesIO
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
from fontTools.ttLib import TTFont
from PIL import Image, ImageDraw, ImageFont

root=Path(__file__).resolve().parents[1]/'resources/bulletin-editor/fonts/emoji'
source=TTFont(root/'pdf-regular.ttf')
cmap=source.getBestCmap()
sequences={chr(code) for code in cmap if code >= 0x203C and code != 0xFE0F}
reverse={glyph:chr(code) for code,glyph in cmap.items()}
if 'GSUB' in source:
    for lookup in source['GSUB'].table.LookupList.Lookup:
        for subtable in lookup.SubTable:
            for first,ligatures in getattr(subtable,'ligatures',{}).items():
                for ligature in ligatures:
                    components=[first,*ligature.Component]
                    if all(glyph in reverse for glyph in components):
                        sequences.add(''.join(reverse[glyph] for glyph in components))
font=ImageFont.truetype(str(root/'pdf-regular.ttf'),64)
written=set()
with ZipFile(root/'glyphs.zip','w',compression=ZIP_DEFLATED,compresslevel=9) as archive:
    for text in sorted(sequences):
        box=font.getbbox(text);width=box[2]-box[0];height=box[3]-box[1]
        if not width or not height or width>500: continue
        image=Image.new('RGBA',(width+12,height+12),(255,255,255,0))
        ImageDraw.Draw(image).text((6-box[0],6-box[1]),text,font=font,fill=(30,43,85,255))
        stream=BytesIO();image.save(stream,format='PNG')
        key='-'.join(format(ord(char),'x') for char in text if ord(char)!=0xFE0F)
        if key in written: continue
        written.add(key)
        entry=ZipInfo(key+'.png',date_time=(2026,1,1,0,0,0));entry.compress_type=ZIP_DEFLATED
        archive.writestr(entry,stream.getvalue())
print('Offline emoji atlas generated.')
