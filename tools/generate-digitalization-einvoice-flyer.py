#!/usr/bin/env python3
"""Erzeugt den Anesda-Nord-Flyer für Digitalisierung und E-Rechnung."""

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
PURPLE = HexColor('#7357df')


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

    canvas.setFillColor(CYAN)
    canvas.rect(0, 0, 15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(NAVY)
    canvas.rect(15, 0, W-15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 9.5)
    canvas.drawString(45, 380, 'DIGITALE ABLÄUFE FÜR DEN MITTELSTAND')
    title = ParagraphStyle('cover-title', fontName='Anesda-Bold', fontSize=27,
                           leading=30, textColor=white)
    paragraph(canvas, 'Digitalisierung<br/>&amp; E-Rechnung', 45, 347, W-90, title)
    canvas.setFont('Anesda-Bold', 14)
    canvas.drawString(45, 255, 'Pflichten erfüllen. Abläufe vereinfachen. Zeit gewinnen.')
    body = ParagraphStyle('cover-body', fontName='Anesda', fontSize=10.2,
                          leading=13.5, textColor=HexColor('#dce8ed'))
    paragraph(
        canvas,
        'Wir machen Ihre Rechnungsprozesse bereit für die neuen Anforderungen - vom sicheren Empfang über strukturierte Formate bis zur nachvollziehbaren Ablage und Übergabe an die Buchhaltung.',
        45, 228, W-90, body
    )

    labels = [('EMPFANG', 'zentral und sichtbar'), ('VERSAND', 'strukturiert und geprüft'), ('ARCHIV', 'geordnet und auffindbar')]
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


def timeline_card(canvas, x, y, width, date, title, text, accent):
    canvas.setFillColor(PALE)
    canvas.roundRect(x, y-79, width, 86, 11, fill=1, stroke=0)
    canvas.setFillColor(accent)
    canvas.roundRect(x, y-79, 8, 86, 4, fill=1, stroke=0)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawString(x+18, y-18, date)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(x+18, y-35, title)
    style = ParagraphStyle('timeline', fontName='Anesda', fontSize=7.0, leading=8.6, textColor=MUTED)
    paragraph(canvas, text, x+18, y-45, width-31, style)


def page_two(canvas):
    logo(canvas)
    canvas.setFillColor(PURPLE)
    canvas.roundRect(W-190, H-67, 31, 31, 8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(W-174.5, H-56, 'ER')
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawRightString(W-40, H-51, 'Digitalisierung')
    canvas.drawRightString(W-40, H-62, '& E-Rechnung')
    canvas.setFillColor(PURPLE)
    canvas.rect(40, H-80, W-80, 3, fill=1, stroke=0)
    footer(canvas, 2)

    canvas.setFillColor(TEAL_PALE)
    canvas.roundRect(40, H-130, 145, 27, 13, fill=1, stroke=0)
    canvas.setFillColor(TEAL)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(112.5, H-120, 'FRISTEN IM BLICK BEHALTEN')
    h1 = ParagraphStyle('h1', fontName='Anesda-Bold', fontSize=22, leading=25, textColor=INK)
    paragraph(canvas, 'Jetzt vorbereiten statt später improvisieren.', 40, H-158, W-80, h1)
    body = ParagraphStyle('body', fontName='Anesda', fontSize=9.4, leading=12.5, textColor=MUTED)
    paragraph(
        canvas,
        'Eine einfache PDF ist seit 2025 keine E-Rechnung mehr. Entscheidend sind strukturierte, maschinenlesbare Daten - zum Beispiel als XRechnung oder geeignetes ZUGFeRD.',
        40, H-210, W-80, body
    )

    timeline_card(canvas, 40, H-267, 164, 'SEIT 01.01.2025', 'Empfang sicherstellen',
                  'Inländische Unternehmen müssen E-Rechnungen empfangen können. Ein E-Mail-Postfach genügt technisch.', TEAL)
    timeline_card(canvas, 216, H-267, 164, 'BIS 31.12.2026', 'Allgemeine Übergangszeit',
                  'Papier oder PDF mit Zustimmung des Empfängers bleiben beim Versand grundsätzlich noch möglich.', CYAN)
    timeline_card(canvas, 392, H-267, 164, '2027 UND 2028', 'Pflicht wird gestaffelt',
                  'Ab 2027 gilt die Übergangsfrist nur noch bis 800.000 EUR Vorjahresumsatz. Ab 2028 wird die E-Rechnung im inländischen B2B grundsätzlich Pflicht.', ORANGE)

    canvas.setFillColor(NAVY)
    canvas.roundRect(40, 300, W-80, 118, 14, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 12)
    canvas.drawString(60, 390, 'Wir begleiten den gesamten Ablauf')
    support = [
        ('01', 'Ist-Aufnahme', 'Postfächer, Programme und Zuständigkeiten prüfen'),
        ('02', 'Umsetzung', 'Empfang, Erstellung, Prüfung und Übergabe verbinden'),
        ('03', 'Betrieb', 'Ablage, Lesbarkeit, Schulung und Support absichern'),
    ]
    x = 60
    for number, title, text in support:
        canvas.setFillColor(PURPLE if number == '02' else TEAL)
        canvas.circle(x+14, 348, 14, fill=1, stroke=0)
        canvas.setFillColor(white)
        canvas.setFont('Anesda-Bold', 7)
        canvas.drawCentredString(x+14, 345.5, number)
        canvas.setFont('Anesda-Bold', 9)
        canvas.drawString(x+36, 354, title)
        style = ParagraphStyle('support', fontName='Anesda', fontSize=7.3, leading=9.2, textColor=HexColor('#dce8ed'))
        paragraph(canvas, text, x+36, 342, 118, style)
        x += 169

    canvas.setFillColor(PALE)
    canvas.roundRect(40, 178, W-80, 91, 12, fill=1, stroke=0)
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 11)
    canvas.drawString(58, 246, 'Damit aus einer Pflicht ein besserer Prozess wird')
    tasks = ParagraphStyle('tasks', fontName='Anesda', fontSize=8.4, leading=12, textColor=MUTED)
    paragraph(
        canvas,
        '<b>Formate:</b> XRechnung und ZUGFeRD ab Version 2.0.1 prüfen und verarbeiten<br/>'
        '<b>Automatisierung:</b> Rechnungen zuordnen, freigeben und an die Buchhaltung übergeben<br/>'
        '<b>Aufbewahrung:</b> den strukturierten Teil unverändert und auffindbar für acht Jahre sichern',
        58, 229, W-116, tasks
    )

    canvas.setFillColor(TEAL)
    canvas.roundRect(40, 104, W-80, 49, 10, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 12)
    canvas.drawString(58, 132, 'E-Rechnungs-Check vereinbaren: 038780 579999')
    canvas.setFont('Anesda', 8)
    canvas.drawString(58, 117, 'Bestand prüfen · passenden Weg festlegen · gemeinsam produktiv umsetzen')

    source = ParagraphStyle('source', fontName='Anesda', fontSize=5.6, leading=7.2, textColor=MUTED)
    paragraph(
        canvas,
        'Grundlage: BMF, FAQ zur verpflichtenden E-Rechnung, Stand 2026; §§ 14 und 27 UStG. '
        'Die Darstellung ist eine allgemeine Orientierung und ersetzt keine Steuer- oder Rechtsberatung.',
        40, 87, W-80, source
    )


def build(output, photo):
    font_setup()
    output.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(output), pagesize=A4)
    canvas.setTitle('Digitalisierung und E-Rechnung - Kundenflyer')
    canvas.setAuthor('Anesda Nord UG (haftungsbeschränkt)')
    canvas.setSubject('Kundenflyer der Anesda Nord UG (haftungsbeschränkt)')
    canvas.setKeywords('Anesda Nord, Digitalisierung, E-Rechnung, XRechnung, ZUGFeRD, Kundenflyer')
    cover(canvas, photo)
    canvas.showPage()
    page_two(canvas)
    canvas.showPage()
    canvas.save()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Aufruf: generate-digitalization-einvoice-flyer.py AUSGABE.pdf TITELBILD.jpg')
    build(Path(sys.argv[1]), Path(sys.argv[2]))
