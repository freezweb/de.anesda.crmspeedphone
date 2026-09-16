#!/usr/bin/env python3
"""Erzeugt die Anesda-Nord-Produktbroschüre für Maschinenvernetzung."""

from pathlib import Path
import sys

from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_CENTER
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
PURPLE = HexColor('#7357df')
PURPLE_PALE = HexColor('#ece8fb')


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
    canvas.drawRightString(W-40, 24, f'{page}/4 · Stand 09/2026')


def header(canvas, page, title='Maschinenvernetzung & Automatisierung'):
    logo(canvas)
    canvas.setFillColor(PURPLE)
    canvas.roundRect(W-190, H-67, 31, 31, 8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(W-174.5, H-56, 'MA')
    canvas.setFillColor(INK)
    canvas.setFont('Anesda-Bold', 8)
    if title == 'Maschinenvernetzung & Automatisierung':
        canvas.drawRightString(W-40, H-51, 'Maschinenvernetzung &')
        canvas.drawRightString(W-40, H-62, 'Automatisierung')
    else:
        canvas.drawRightString(W-40, H-57, title)
    canvas.setFillColor(PURPLE)
    canvas.rect(40, H-80, W-80, 3, fill=1, stroke=0)
    footer(canvas, page)


def cover(canvas, photo):
    image = ImageReader(photo)
    iw, ih = image.getSize()
    area_h = 530
    scale = max(W/iw, area_h/ih)
    draw_w, draw_h = iw*scale, ih*scale
    canvas.drawImage(image, (W-draw_w)/2, H-area_h+(area_h-draw_h)/2,
                     draw_w, draw_h, preserveAspectRatio=True, mask='auto')
    canvas.setFillColor(white)
    canvas.roundRect(34, H-74, 240, 48, 12, fill=1, stroke=0)
    logo(canvas, 49, H-64)
    canvas.setFillColor(PURPLE)
    canvas.rect(0, 0, 15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(NAVY)
    canvas.rect(15, 0, W-15, H-area_h+8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(45, 272, 'DIE LÖSUNG FÜR IHRE PRODUKTION')
    title = ParagraphStyle('cover-title', fontName='Anesda-Bold', fontSize=29,
                           leading=32, textColor=white)
    paragraph(canvas, 'Maschinenvernetzung<br/>&amp; Automatisierung', 45, 235, W-85, title)
    canvas.setFont('Anesda-Bold', 16)
    canvas.drawString(45, 128, 'Daten verbinden. Abläufe steuern. Stillstände erkennen.')
    body = ParagraphStyle('cover-body', fontName='Anesda', fontSize=10.5,
                          leading=13, textColor=HexColor('#dce8ed'))
    paragraph(canvas,
              'Wir vernetzen Maschinen, Steuerungen und bestehende Software - vom einzelnen Signal bis zum übersichtlichen Betriebsbild.',
              45, 94, W-90, body)
    canvas.setFillColor(HexColor('#c9d8df'))
    canvas.setFont('Anesda', 7)
    canvas.drawString(39, 23, '038780 579999 · social@anesda-nord.de · anesda-nord.de')
    canvas.drawRightString(W-39, 23, '1/4 · Stand 09/2026')


def page_two(canvas, photo):
    header(canvas, 2)
    canvas.setFillColor(PURPLE_PALE)
    canvas.roundRect(40, H-130, 120, 27, 13, fill=1, stroke=0)
    canvas.setFillColor(PURPLE)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(100, H-120, 'IHR NUTZEN')
    h1 = ParagraphStyle('h1', fontName='Anesda-Bold', fontSize=25, leading=28, textColor=INK)
    paragraph(canvas, 'Aus einzelnen Anlagen wird ein verständlicher Prozess.', 40, H-157, W-80, h1)
    body = ParagraphStyle('body', fontName='Anesda', fontSize=11, leading=15, textColor=MUTED)
    paragraph(canvas,
              'Maschinendaten werden nicht nur gesammelt: Sie werden eingeordnet, an den richtigen Stellen bereitgestellt und für konkrete Abläufe nutzbar gemacht.',
              40, H-234, W-80, body)
    items = [
        ('1', 'Daten erfassen', 'Signale und Zustände aus Maschinen, Steuerungen und Sensorik zusammenführen.'),
        ('2', 'Zusammenhänge erkennen', 'Stillstände, Engpässe und wiederkehrende Abweichungen schneller sichtbar machen.'),
        ('3', 'Abläufe verbessern', 'Freigaben, Meldungen und Datenübergaben nachvollziehbar automatisieren.'),
    ]
    x_positions = [40, 220, 400]
    for x, (number, title, text) in zip(x_positions, items):
        canvas.setFillColor(PURPLE_PALE)
        canvas.circle(x+20, H-330, 20, fill=1, stroke=0)
        canvas.setFillColor(PURPLE)
        canvas.setFont('Anesda-Bold', 9)
        canvas.drawCentredString(x+20, H-333, number)
        canvas.setFillColor(INK)
        canvas.setFont('Anesda-Bold', 11)
        canvas.drawString(x, H-375, title)
        small = ParagraphStyle('small', fontName='Anesda', fontSize=8.5, leading=11, textColor=MUTED)
        paragraph(canvas, text, x, H-394, 145, small)
    image = ImageReader(photo)
    iw, ih = image.getSize()
    box_x, box_y, box_w, box_h = 40, 66, W-80, 235
    scale = max(box_w/iw, box_h/ih)
    dw, dh = iw*scale, ih*scale
    canvas.saveState()
    path = canvas.beginPath(); path.roundRect(box_x, box_y, box_w, box_h, 14)
    canvas.clipPath(path, stroke=0)
    canvas.drawImage(image, box_x+(box_w-dw)/2, box_y+(box_h-dh)/2, dw, dh)
    canvas.restoreState()
    canvas.setFillColor(NAVY)
    canvas.roundRect(58, 84, 330, 53, 8, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 10)
    canvas.drawString(72, 116, 'Bestehende Technik weiter nutzen.')
    canvas.setFont('Anesda', 8)
    canvas.drawString(72, 99, 'Schnittstellen werden passend zum Bestand geplant - nicht zum Prospekt.')


def page_three(canvas):
    header(canvas, 3)
    canvas.setFillColor(PURPLE_PALE)
    canvas.roundRect(40, H-130, 120, 27, 13, fill=1, stroke=0)
    canvas.setFillColor(PURPLE)
    canvas.setFont('Anesda-Bold', 8)
    canvas.drawCentredString(100, H-120, 'BAUSTEINE')
    h1 = ParagraphStyle('h1b', fontName='Anesda-Bold', fontSize=24, leading=27, textColor=INK)
    paragraph(canvas, 'Vom Maschinensignal bis zur nutzbaren Information.', 40, H-158, W-80, h1)
    body = ParagraphStyle('bodyb', fontName='Anesda', fontSize=10, leading=13, textColor=MUTED)
    paragraph(canvas, 'Modular geplant, sauber dokumentiert und schrittweise ausbaubar.', 40, H-220, W-80, body)
    cards = [
        ('01', 'Bestandsaufnahme', 'Maschinen, Steuerungen, Datenpunkte und vorhandene Schnittstellen erfassen.'),
        ('02', 'Maschinenanbindung', 'Daten über passende Protokolle oder sichere Gateways verfügbar machen.'),
        ('03', 'Datendrehscheibe', 'Informationen strukturiert zusammenführen, puffern und weitergeben.'),
        ('04', 'Visualisierung & Meldung', 'Zustände, Kennzahlen, Alarme und Verläufe verständlich darstellen.'),
        ('05', 'Automatisierte Abläufe', 'Freigaben, Benachrichtigungen und Datenübergaben nachvollziehbar auslösen.'),
        ('06', 'Software-Anbindung', 'Bestehende ERP-, MES- oder individuelle Anwendungen gezielt einbeziehen.'),
    ]
    for index, (number, title, text) in enumerate(cards):
        col, row = index % 2, index // 2
        x, y = 40 + col*272, H-300-row*137
        canvas.setFillColor(PALE)
        canvas.roundRect(x, y-100, 257, 110, 12, fill=1, stroke=0)
        canvas.setFillColor(PURPLE)
        canvas.roundRect(x, y-100, 11, 110, 6, fill=1, stroke=0)
        canvas.setFillColor(PURPLE_PALE)
        canvas.circle(x+38, y-18, 16, fill=1, stroke=0)
        canvas.setFillColor(PURPLE)
        canvas.setFont('Anesda-Bold', 7.5)
        canvas.drawCentredString(x+38, y-21, number)
        canvas.setFillColor(INK)
        canvas.setFont('Anesda-Bold', 11)
        canvas.drawString(x+65, y-16, title)
        style = ParagraphStyle('card', fontName='Anesda', fontSize=8.2, leading=10.5, textColor=MUTED)
        paragraph(canvas, text, x+23, y-54, 215, style)
    canvas.setFillColor(HexColor('#fff7df'))
    canvas.roundRect(40, 65, W-80, 48, 8, fill=1, stroke=0)
    canvas.setFillColor(HexColor('#654b00'))
    canvas.setFont('Anesda-Bold', 8.5)
    canvas.drawString(55, 93, 'Sicher geplant:')
    safe = ParagraphStyle('safe', fontName='Anesda', fontSize=7.8, leading=10, textColor=HexColor('#654b00'))
    paragraph(canvas, 'Freigaben, Netzsegmentierung und sichere Rückfallwege werden gemeinsam festgelegt - keine unkontrollierten Eingriffe in laufende Anlagen.', 120, 100, W-175, safe)


def page_four(canvas):
    header(canvas, 4)
    h1 = ParagraphStyle('h1c', fontName='Anesda-Bold', fontSize=25, leading=28, textColor=INK)
    paragraph(canvas, 'Mit einem klar abgegrenzten Pilotprojekt starten.', 40, H-130, W-80, h1)
    body = ParagraphStyle('bodyc', fontName='Anesda', fontSize=11, leading=15, textColor=MUTED)
    paragraph(canvas,
              'Wir betrachten einen konkreten Prozess, prüfen die verfügbaren Signale und zeigen, welcher Nutzen mit vertretbarem Aufwand erreichbar ist.',
              40, H-202, W-80, body)
    situations = [
        ('Gewachsener Maschinenpark', 'Unterschiedliche Baujahre und Hersteller sollen gemeinsam sichtbar werden.'),
        ('Medienbrüche', 'Werte werden noch abgelesen, übertragen oder mehrfach von Hand erfasst.'),
        ('Fehlende Transparenz', 'Stillstand, Takt, Ausschuss oder Verbrauch lassen sich nur schwer nachvollziehen.'),
    ]
    y = H-315
    for title, text in situations:
        canvas.setFillColor(PALE)
        canvas.roundRect(40, y-74, W-80, 82, 12, fill=1, stroke=0)
        canvas.setFillColor(PURPLE)
        canvas.rect(40, y-74, 9, 82, fill=1, stroke=0)
        canvas.setFillColor(INK)
        canvas.setFont('Anesda-Bold', 11)
        canvas.drawString(68, y-22, title)
        style = ParagraphStyle('situation', fontName='Anesda', fontSize=9, leading=12, textColor=MUTED)
        paragraph(canvas, text, 68, y-38, W-145, style)
        y -= 104
    canvas.setFillColor(NAVY)
    canvas.roundRect(40, 75, W-80, 125, 14, fill=1, stroke=0)
    canvas.setFillColor(white)
    canvas.setFont('Anesda-Bold', 18)
    canvas.drawString(62, 165, 'Lassen Sie uns eine Anlage gemeinsam ansehen.')
    callout = ParagraphStyle('callout', fontName='Anesda', fontSize=10, leading=14, textColor=HexColor('#dce8ed'))
    paragraph(canvas,
              'Technische Bestandsaufnahme, begrenzter Pilot, klare Ziele - danach entscheiden Sie.',
              62, 140, W-125, callout)
    canvas.setFillColor(CYAN)
    canvas.roundRect(62, 91, 200, 27, 7, fill=1, stroke=0)
    canvas.setFillColor(NAVY)
    canvas.setFont('Anesda-Bold', 9)
    canvas.drawCentredString(162, 101, '038780 579999 · anesda-nord.de')


def build(output, photo):
    font_setup()
    output.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(output), pagesize=A4)
    canvas.setTitle('Maschinenvernetzung und Automatisierung - Kundenbroschüre')
    canvas.setAuthor('Anesda Nord UG (haftungsbeschränkt)')
    canvas.setSubject('Kundenorientierte Produktbroschüre der Anesda Nord UG (haftungsbeschränkt)')
    canvas.setKeywords('Anesda Nord, Maschinenvernetzung, Automatisierung, Kundenflyer, Produktbroschüre')
    cover(canvas, photo); canvas.showPage()
    page_two(canvas, photo); canvas.showPage()
    page_three(canvas); canvas.showPage()
    page_four(canvas); canvas.showPage()
    canvas.save()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Aufruf: generate-machine-networking-flyer.py AUSGABE.pdf TITELBILD.jpg')
    build(Path(sys.argv[1]), Path(sys.argv[2]))
