#!/usr/bin/env python3
"""Erzeugt den einseitigen Kundenflyer für Maschinen-Retrofit."""

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
PALE = HexColor('#eef5f7')
CYAN = HexColor('#08a7dd')
ORANGE = HexColor('#edaa4b')


def setup_fonts():
    fonts = Path('C:/Windows/Fonts')
    pdfmetrics.registerFont(TTFont('Anesda', str(fonts / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('Anesda-Bold', str(fonts / 'arialbd.ttf')))


def paragraph(canvas, content, x, top, width, size, leading, color, bold=False):
    style = ParagraphStyle(
        'retrofit', fontName='Anesda-Bold' if bold else 'Anesda',
        fontSize=size, leading=leading, textColor=color,
    )
    item = Paragraph(content, style)
    _, height = item.wrap(width, H)
    item.drawOn(canvas, x, top - height)
    return height


def draw_logo(canvas, x, y):
    canvas.setFillColor(CYAN)
    canvas.rect(x, y + 15, 8, 8, fill=1, stroke=0)
    canvas.rect(x + 32, y - 1, 18, 8, fill=1, stroke=0)
    canvas.setFillColor(HexColor('#d9dddf'))
    canvas.rect(x, y - 1, 8, 14, fill=1, stroke=0)
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
    area_h = 336
    image = ImageReader(image_path)
    iw, ih = image.getSize()
    scale = max(W / iw, area_h / ih)
    draw_w, draw_h = iw * scale, ih * scale
    canvas.saveState()
    clip = canvas.beginPath()
    clip.rect(0, H - area_h, W, area_h)
    canvas.clipPath(clip, stroke=0)
    canvas.drawImage(image, (W - draw_w) / 2, H - area_h + (area_h - draw_h) / 2,
                     draw_w, draw_h, mask='auto')
    canvas.setFillColor(NAVY)
    canvas.setFillAlpha(.86)
    canvas.rect(0, H - area_h, 350, area_h, fill=1, stroke=0)
    canvas.restoreState()

    canvas.setFillColor(white)
    canvas.roundRect(34, H - 80, 244, 52, 11, fill=1, stroke=0)
    draw_logo(canvas, 49, H - 68)
    canvas.setFillColor(CYAN)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(40, H - 132, 'MASCHINEN-RETROFIT')
    paragraph(canvas, 'Bewährte Maschine.<br/>Neue Möglichkeiten.', 40, H - 149,
              315, 27, 31, white, bold=True)
    paragraph(canvas, 'Wir analysieren Ihre Anlage, modernisieren die Steuerung '
              'und erweitern Funktionen, wo es sinnvoll ist.',
              40, H - 244, 297, 11, 16, white)
    canvas.setFillColor(ORANGE)
    canvas.rect(0, H - area_h, 80, 6, fill=1, stroke=0)


def draw_step(canvas, x, number, heading, body):
    width = (W - 94) / 3
    canvas.setFillColor(PALE)
    canvas.roundRect(x, 224, width, 131, 11, fill=1, stroke=0)
    canvas.setFillColor(CYAN)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawString(x + 14, 329, number)
    canvas.setFillColor(INK)
    paragraph(canvas, heading, x + 14, 316, width - 28, 11, 13, INK, bold=True)
    paragraph(canvas, body, x + 14, 284, width - 28, 8.5, 12, MUTED)


def draw_body(canvas):
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 17)
    canvas.drawString(40, 475, 'Modernisieren statt vorschnell ersetzen.')
    paragraph(canvas, 'Eine mechanisch brauchbare Maschine muss nicht am Ende sein, '
              'nur weil ihre Steuerung veraltet ist oder neue Abläufe fehlen. '
              'Wir erarbeiten mit Ihnen einen nachvollziehbaren Retrofit-Plan – '
              'von der Bestandsaufnahme bis zur Inbetriebnahme.',
              40, 457, W - 80, 9.7, 14, MUTED)
    draw_step(canvas, 40, '01 / ANALYSE', 'Bestand verstehen',
              'Steuerung, Sensorik, Schnittstellen und Prozesse aufnehmen; Risiken, '
              'Ziele und Umbauaufwand klären.')
    draw_step(canvas, 47 + (W - 94) / 3, '02 / UMBAU', 'Steuerung erneuern',
              'Veraltete SPS und Bedienung gezielt austauschen und bestehende '
              'Maschinenfunktionen sauber übernehmen.')
    draw_step(canvas, 54 + 2 * (W - 94) / 3, '03 / ERWEITERUNG', 'Mehr herausholen',
              'Neue Sensorik, Datenanbindung, Visualisierung oder zusätzliche '
              'Abläufe passend zum Betrieb ergänzen.')

    canvas.setFillColor(NAVY)
    canvas.roundRect(40, 82, W - 80, 120, 12, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 14)
    canvas.drawString(58, 170, 'Welche Maschine soll wieder mehr können?')
    paragraph(canvas, 'Schildern Sie uns den heutigen Stand und was künftig möglich sein soll. '
              'Wir prüfen die technische Machbarkeit und besprechen den nächsten Schritt.',
              58, 157, W - 116, 8.8, 12, HexColor('#dce8ed'))
    canvas.setFillColor(CYAN)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(58, 105, '038780 579999  ·  sales@anesda-nord.de')
    canvas.linkURL('mailto:sales@anesda-nord.de', (200, 100, 410, 121), relative=0)

    canvas.setFillColor(MUTED)
    canvas.setFont('Anesda', 7)
    canvas.drawString(40, 54, 'Anesda Nord UG (haftungsbeschränkt) · Parkstraße 5 · 19309 Lanz')
    canvas.drawString(40, 40, 'anesda-nord.de/maschinen-retrofit')
    canvas.drawRightString(W - 40, 40, '© anesda · 10/2026')
    canvas.linkURL('https://anesda-nord.de/maschinen-retrofit', (40, 36, 227, 52), relative=0)


def build(output, image_path):
    setup_fonts()
    output.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(output), pagesize=A4)
    canvas.setTitle('Maschinen-Retrofit | Anesda Nord')
    canvas.setAuthor('Anesda Nord UG (haftungsbeschränkt)')
    canvas.setSubject('Maschinen analysieren, Steuerungen modernisieren, Funktionen erweitern')
    canvas.setKeywords('Maschinen-Retrofit, SPS, Steuerungstausch, Modernisierung, Anesda Nord')
    draw_hero(canvas, image_path)
    draw_body(canvas)
    canvas.showPage()
    canvas.save()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Aufruf: generate-machine-retrofit-flyer.py AUSGABE.pdf TITELBILD.png')
    build(Path(sys.argv[1]), Path(sys.argv[2]))
