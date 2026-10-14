#!/usr/bin/env python3
"""Erzeugt den einseitigen Anesda-Nord-Flyer „Die technischen Problemlöser“."""

from pathlib import Path
import sys

from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import Paragraph


W, H = A4
NAVY = HexColor('#082f43')
INK = HexColor('#102f42')
MUTED = HexColor('#51697a')
PALE = HexColor('#f1f6f8')
CYAN = HexColor('#08a7dd')
TEAL = HexColor('#0c8f8a')
ORANGE = HexColor('#f0a22e')


def setup_fonts():
    fonts = Path('C:/Windows/Fonts')
    pdfmetrics.registerFont(TTFont('Anesda', str(fonts / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('Anesda-Bold', str(fonts / 'arialbd.ttf')))


def paragraph(canvas, text, x, y_top, width, font_size, leading, color, bold=False):
    style = ParagraphStyle(
        'flyer', fontName='Anesda-Bold' if bold else 'Anesda',
        fontSize=font_size, leading=leading, textColor=color,
    )
    item = Paragraph(text, style)
    _, height = item.wrap(width, H)
    item.drawOn(canvas, x, y_top - height)
    return height


def draw_logo(canvas, x, y):
    unit = 8
    canvas.setFillColor(CYAN)
    canvas.rect(x, y + 15, unit, unit, fill=1, stroke=0)
    canvas.rect(x + 32, y - 1, 18, unit, fill=1, stroke=0)
    canvas.setFillColor(HexColor('#d9dddf'))
    canvas.rect(x, y - 1, unit, 14, fill=1, stroke=0)
    canvas.rect(x + 31, y + 14, 19, 20, fill=1, stroke=0)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 11)
    for offset, line in [(23, 'AN'), (12, 'ES'), (1, 'DA')]:
        canvas.drawString(x + 10, y + offset, line)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(x + 60, y + 20, 'ANESDA NORD')
    canvas.setFillColor(MUTED)
    canvas.setFont('Anesda', 6.5)
    canvas.drawString(x + 60, y + 9, 'Software · Geräte · Betrieb')


def draw_hero(canvas, image_path):
    area_h = 363
    image = ImageReader(image_path)
    iw, ih = image.getSize()
    scale = max(W / iw, area_h / ih)
    draw_w, draw_h = iw * scale, ih * scale
    canvas.saveState()
    path = canvas.beginPath()
    path.rect(0, H - area_h, W, area_h)
    canvas.clipPath(path, stroke=0)
    canvas.drawImage(image, (W - draw_w) / 2, H - area_h + (area_h - draw_h) / 2,
                     draw_w, draw_h, mask='auto')
    canvas.setFillColor(NAVY)
    canvas.setFillAlpha(0.78)
    canvas.rect(0, H - area_h, 355, area_h, fill=1, stroke=0)
    canvas.restoreState()

    canvas.setFillColor(white)
    canvas.roundRect(33, H - 81, 244, 52, 11, fill=1, stroke=0)
    draw_logo(canvas, 48, H - 69)

    canvas.setFillColor(CYAN)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(40, H - 146, 'ANESDA NORD')
    canvas.setFillColor(white)
    paragraph(canvas, 'Die technischen<br/>Problemlöser', 40, H - 166, 325,
              31, 35, white, bold=True)
    paragraph(canvas, 'Ihr Problem passt in keine Schublade?<br/>Wir kümmern uns um einen gangbaren Weg.',
              40, H - 267, 305, 12, 17, white)
    canvas.setFillColor(CYAN)
    canvas.rect(0, H - area_h, 15, 6, fill=1, stroke=0)
    canvas.setFillColor(ORANGE)
    canvas.rect(15, H - area_h, 70, 6, fill=1, stroke=0)


def draw_process_card(canvas, x, number, title, body, accent):
    width = (W - 92) / 3
    canvas.setFillColor(PALE)
    canvas.roundRect(x, 212, width, 122, 10, fill=1, stroke=0)
    canvas.setFillColor(accent)
    canvas.circle(x + 25, 309, 12, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(x + 25, 306, number)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(x + 43, 306, title)
    paragraph(canvas, body, x + 13, 285, width - 26, 8.3, 11.3, MUTED)


def draw_body(canvas):
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 18)
    canvas.drawString(39, 445, 'Ein Ansprechpartner. Viele technische Wege.')
    paragraph(
        canvas,
        'Ob IT, Netzwerk, Maschinen, Sensorik, Software oder ein Problem dazwischen: '
        'Beschreiben Sie uns, was nicht funktioniert oder was möglich werden soll. '
        'Wir hören zu, gehen der Ursache nach und kümmern uns um die Umsetzung – '
        'bei Bedarf gemeinsam mit Ihren vorhandenen Dienstleistern.',
        39, 428, W - 78, 9.6, 14, MUTED,
    )
    canvas.setFillColor(TEAL)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawString(39, 353, 'SO GEHEN WIR VOR')
    draw_process_card(canvas, 39, '01', 'Verstehen',
                      'Wir klären, was ausfällt, bremst oder noch fehlt – vor Ort oder gemeinsam mit Ihrem Team.', TEAL)
    draw_process_card(canvas, 46 + (W - 92) / 3, '02', 'Lösen',
                      'Wir verbinden passende Technik und Fachwissen zu einer nachvollziehbaren Lösung.', CYAN)
    draw_process_card(canvas, 53 + 2 * (W - 92) / 3, '03', 'Dranbleiben',
                      'Wir begleiten die Umsetzung und bleiben für Fragen, Änderungen und Betrieb ansprechbar.', ORANGE)

    canvas.setFillColor(NAVY)
    canvas.roundRect(39, 82, W - 78, 110, 12, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 14)
    canvas.drawString(56, 161, 'Welches technische Problem beschäftigt Sie?')
    paragraph(canvas, 'Schildern Sie uns die Herausforderung – auch wenn noch nicht klar ist, '
              'welche Technik oder welches Gewerk zuständig ist.',
              56, 151, W - 112, 8.6, 12, HexColor('#dce8ed'))
    canvas.setFillColor(CYAN)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(56, 105, '038780 579999  ·  sales@anesda-nord.de')
    canvas.linkURL('mailto:sales@anesda-nord.de', (198, 100, 408, 120), relative=0)

    canvas.setFillColor(MUTED)
    canvas.setFont('Anesda', 7)
    canvas.drawString(39, 54, 'Anesda Nord UG (haftungsbeschränkt) · Parkstraße 5 · 19309 Lanz')
    canvas.drawString(39, 40, 'anesda-nord.de/kontakt')
    canvas.drawRightString(W - 39, 40, '© anesda · 10/2026')
    canvas.linkURL('https://anesda-nord.de/kontakt', (39, 36, 160, 52), relative=0)


def build(output, image_path):
    setup_fonts()
    output.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(output), pagesize=A4)
    canvas.setTitle('Anesda Nord – Die technischen Problemlöser')
    canvas.setAuthor('Anesda Nord UG (haftungsbeschränkt)')
    canvas.setSubject('Ein Ansprechpartner für technische Herausforderungen')
    canvas.setKeywords('Anesda Nord, technische Problemlöser, IT, Netzwerke, Maschinen, Sensorik, Software')
    draw_hero(canvas, image_path)
    draw_body(canvas)
    canvas.showPage()
    canvas.save()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Aufruf: generate-technical-problem-solvers-flyer.py AUSGABE.pdf TITELBILD.png')
    build(Path(sys.argv[1]), Path(sys.argv[2]))
