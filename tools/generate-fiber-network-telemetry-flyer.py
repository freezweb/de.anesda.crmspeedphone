#!/usr/bin/env python3
"""Erzeugt den Anesda-Nord-Flyer für Glasfaser, Vernetzung und Telemetrie."""

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
TEAL_PALE = HexColor('#e3f5f2')
ORANGE = HexColor('#f0a22e')


def font_setup():
    fonts = Path('C:/Windows/Fonts')
    pdfmetrics.registerFont(TTFont('Anesda', str(fonts / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('Anesda-Bold', str(fonts / 'arialbd.ttf')))


def paragraph(canvas, text, x, y_top, width, style):
    item = Paragraph(text, style)
    _, height = item.wrap(width, H)
    item.drawOn(canvas, x, y_top - height)
    return height


def logo(canvas, x=40, y=H-65):
    unit = 8
    canvas.setFillColor(CYAN)
    canvas.rect(x, y+15, unit, unit, fill=1, stroke=0)
    canvas.rect(x+32, y-1, 18, unit, fill=1, stroke=0)
    canvas.setFillColor(HexColor('#d9dddf'))
    canvas.rect(x, y-1, unit, 14, fill=1, stroke=0)
    canvas.rect(x+31, y+14, 19, 20, fill=1, stroke=0)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 11)
    canvas.drawString(x+10, y+23, 'AN')
    canvas.drawString(x+10, y+12, 'ES')
    canvas.drawString(x+10, y+1, 'DA')
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(x+60, y+20, 'ANESDA NORD')
    canvas.setFillColor(MUTED)
    canvas.setFont('Anesda', 6.5)
    canvas.drawString(x+60, y+9, 'Software · Geräte · Betrieb')


def footer(canvas, page):
    canvas.setFillColor(MUTED)
    canvas.setFont('Anesda', 7)
    canvas.drawString(40, 24, '038780 579999 · social@anesda-nord.de · anesda-nord.de')
    canvas.drawRightString(W-40, 24, f'{page}/2 · Stand 09/2026')


def cover(canvas, photo):
    image = ImageReader(photo)
    iw, ih = image.getSize()
    area_h = 440
    scale = max(W/iw, area_h/ih)
    draw_w, draw_h = iw*scale, ih*scale
    canvas.drawImage(image, (W-draw_w)/2, H-area_h+(area_h-draw_h)/2,
                     draw_w, draw_h, preserveAspectRatio=True, mask='auto')
    canvas.setFillColor(white)
    canvas.roundRect(34, H-74, 240, 48, 12, fill=1, stroke=0)
    logo(canvas, 49, H-64)

    canvas.setFillColor(TEAL)
    canvas.rect(0, 0, 15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(NAVY)
    canvas.rect(15, 0, W-15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 9.5)
    canvas.drawString(45, 380, 'INFRASTRUKTUR, DIE DATEN VERLÄSSLICH TRANSPORTIERT')
    title = ParagraphStyle('cover-title', fontName='Anesda-Bold', fontSize=27,
                           leading=30, textColor=white)
    paragraph(canvas, 'Glasfaser, Vernetzung<br/>&amp; Telemetrie', 45, 347, W-90, title)
    canvas.setFont('Anesda-Bold', 14)
    canvas.drawString(45, 255, 'Spleißen. Verbinden. Messwerte nutzbar machen.')
    body = ParagraphStyle('cover-body', fontName='Anesda', fontSize=10.2,
                          leading=13.5, textColor=HexColor('#dce8ed'))
    paragraph(
        canvas,
        'Wir verbinden Standorte, Anlagen und Sensorik - von der passiven Glasfaserstrecke über aktive Netzkomponenten bis zur sicheren Übertragung und Auswertung von Betriebsdaten.',
        45, 228, W-90, body
    )

    labels = [('LWL', 'sauber verbunden'), ('NETZ', 'strukturiert aufgebaut'), ('DATEN', 'übersichtlich nutzbar')]
    x = 45
    for short, text in labels:
        canvas.setFillColor(TEAL_PALE)
        canvas.roundRect(x, 92, 153, 46, 8, fill=1, stroke=0)
        canvas.setFillColor(TEAL)
        canvas.setFont('Anesda-Bold', 8)
        canvas.drawString(x+12, 119, short)
        canvas.setFillColor(INK)
        canvas.setFont('Anesda', 7.5)
        canvas.drawString(x+12, 104, text)
        x += 164

    canvas.setFillColor(HexColor('#c9d8df'))
    canvas.setFont('Anesda', 7)
    canvas.drawString(39, 23, '038780 579999 · social@anesda-nord.de · anesda-nord.de')
    canvas.drawRightString(W-39, 23, '1/2 · Stand 09/2026')


def service_card(canvas, x, y, number, title, text, accent):
    canvas.setFillColor(PALE)
    canvas.roundRect(x, y-118, 164, 126, 12, fill=1, stroke=0)
    canvas.setFillColor(accent)
    canvas.roundRect(x, y-118, 9, 126, 5, fill=1, stroke=0)
    canvas.circle(x+35, y-22, 16, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(x+35, y-25, number)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 10.5)
    canvas.drawString(x+58, y-20, title)
    style = ParagraphStyle('service', fontName='Anesda', fontSize=8.2, leading=10.5, textColor=MUTED)
    paragraph(canvas, text, x+20, y-52, 128, style)


def page_two(canvas):
    logo(canvas)
    canvas.setFillColor(TEAL)
    canvas.roundRect(W-190, H-67, 31, 31, 8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(W-174.5, H-56, 'GT')
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawRightString(W-40, H-51, 'Glasfaser, Vernetzung')
    canvas.drawRightString(W-40, H-62, '& Telemetrie')
    canvas.setFillColor(TEAL)
    canvas.rect(40, H-80, W-80, 3, fill=1, stroke=0)
    footer(canvas, 2)

    canvas.setFillColor(TEAL_PALE)
    canvas.roundRect(40, H-130, 128, 27, 13, fill=1, stroke=0)
    canvas.setFillColor(TEAL)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(104, H-120, 'DREI EBENEN · EIN SYSTEM')
    h1 = ParagraphStyle('h1', fontName='Anesda-Bold', fontSize=23, leading=26, textColor=INK)
    paragraph(canvas, 'Von der Faser bis zum verwertbaren Messwert.', 40, H-158, W-80, h1)
    body = ParagraphStyle('body', fontName='Anesda', fontSize=10, leading=13.5, textColor=MUTED)
    paragraph(
        canvas,
        'Wir planen die Übergänge gemeinsam: physische Verbindung, aktives Netzwerk und Telemetrie werden dokumentiert, abgesichert und so aufgebaut, dass spätere Erweiterungen möglich bleiben.',
        40, H-216, W-80, body
    )

    service_card(canvas, 40, H-292, '01', 'Glasfaser',
                 'Fasern vorbereiten und spleißen, Patchfelder oder Muffen einbinden sowie Strecken prüfen und nachvollziehbar dokumentieren.', TEAL)
    service_card(canvas, 216, H-292, '02', 'Vernetzung',
                 'Gebäude, Hallen, Maschinen und Standorte mit passenden Switches, Segmenten und sicheren Übergängen verbinden.', CYAN)
    service_card(canvas, 392, H-292, '03', 'Telemetrie',
                 'Zustände, Verbräuche und Sensorwerte erfassen, übertragen, speichern und für Meldungen oder Dashboards bereitstellen.', ORANGE)

    canvas.setFillColor(NAVY)
    canvas.roundRect(40, 252, W-80, 118, 14, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 12)
    canvas.drawString(60, 341, 'Ein durchgängiger Weg für Ihre Daten')
    steps = [('FASER', TEAL), ('NETZWERK', CYAN), ('GATEWAY', HexColor('#7357df')), ('MESSWERT', ORANGE)]
    x_positions = [74, 205, 336, 467]
    for index, ((label, color), x) in enumerate(zip(steps, x_positions)):
        canvas.setFillColor(color)
        canvas.circle(x, 296, 21, fill=1, stroke=0)
        canvas.setFillColor(white)
        canvas.setFont('Anesda-Bold', 7)
        canvas.drawCentredString(x, 293, label)
        if index < len(steps)-1:
            canvas.setStrokeColor(HexColor('#8fb3c2'))
            canvas.setLineWidth(2)
            canvas.line(x+24, 296, x_positions[index+1]-24, 296)

    canvas.setFillColor(PALE)
    canvas.roundRect(40, 132, W-80, 91, 12, fill=1, stroke=0)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 11)
    canvas.drawString(58, 199, 'Typische Aufgaben')
    tasks = ParagraphStyle('tasks', fontName='Anesda', fontSize=8.7, leading=12, textColor=MUTED)
    paragraph(
        canvas,
        '• Neue LWL-Strecke oder Reparatur einer bestehenden Verbindung&nbsp;&nbsp;&nbsp; '
        '• Hallen- und Standortvernetzung<br/>'
        '• Anbindung von Zählern, Sensorik und Maschinen&nbsp;&nbsp;&nbsp; '
        '• Fernüberwachung, Grenzwerte und Störmeldungen<br/>'
        '• Übergabe an vorhandene Leitstände, Software oder Kundenportale',
        58, 183, W-116, tasks
    )

    canvas.setFillColor(TEAL)
    canvas.roundRect(40, 62, W-80, 48, 10, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 12)
    canvas.drawString(58, 89, 'Strecke oder Anlage gemeinsam prüfen: 038780 579999')
    canvas.setFont('Anesda', 8)
    canvas.drawString(58, 75, 'Bestand aufnehmen · Ziel festlegen · sauber umsetzen · nachvollziehbar dokumentieren')


def build(output, photo):
    font_setup()
    output.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(output), pagesize=A4)
    canvas.setTitle('Glasfaser, Vernetzung und Telemetrie - Kundenflyer')
    canvas.setAuthor('Anesda Nord UG (haftungsbeschränkt)')
    canvas.setSubject('Kundenflyer der Anesda Nord UG (haftungsbeschränkt)')
    canvas.setKeywords('Anesda Nord, Glasfaser, LWL, Spleißen, Vernetzung, Telemetrie, Kundenflyer')
    cover(canvas, photo)
    canvas.showPage()
    page_two(canvas)
    canvas.showPage()
    canvas.save()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Aufruf: generate-fiber-network-telemetry-flyer.py AUSGABE.pdf TITELBILD.jpg')
    build(Path(sys.argv[1]), Path(sys.argv[2]))
