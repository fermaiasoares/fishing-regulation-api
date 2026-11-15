import unittest

from sources import detect_kind, html_to_text, normalize, segment

# Fictional text shaped like a Brazilian normative act; not real legislation.
SAMPLE = """PORTARIA DE EXEMPLO
O DIRETOR resolve:
Art. 1º - Fica estabelecido o período de exemplo na bacia
hidrográfica de teste.
Parágrafo único - Entende-se por bacia o rio principal.
Art. 2º Durante o período fica proibido:
I - capturar espécies nativas;
II - pescar:
a) a menos de 500 m de confluências;
b) em lagoas margi-
nais;
§ 1º A proibição não se aplica à pesca científica.
Art. 10. Esta portaria entra em vigor na data de sua publicação.
"""


class SegmentTest(unittest.TestCase):
    def setUp(self):
        self.units = {unit["id"]: unit for unit in segment(SAMPLE)}

    def test_builds_stable_hierarchical_ids(self):
        self.assertEqual(
            list(self.units),
            ["preamble", "art1", "art1/par-unico", "art2", "art2/I", "art2/II", "art2/II/a", "art2/II/b", "art2/par1", "art10"],
        )

    def test_keeps_unit_text_without_numbering(self):
        self.assertEqual(self.units["art2/II/a"]["text"], "a menos de 500 m de confluências;")
        self.assertEqual(self.units["art1"]["text"], "Fica estabelecido o período de exemplo na bacia hidrográfica de teste.")
        self.assertEqual(self.units["art10"]["label"], "Art. 10")

    def test_normalize_rejoins_hyphenated_words(self):
        self.assertIn(normalize("em lagoas marginais"), normalize("b) em lagoas margi-\nnais;"))
        self.assertEqual(normalize("  Pesca  PROIBIDA \n"), "pesca proibida")

    def test_repeated_numbering_gets_distinct_ids(self):
        units = segment("Art. 1º Primeiro.\nANEXO\nArt. 1º Repetido.")
        self.assertEqual([unit["id"] for unit in units], ["art1", "art1#2"])


class ContentTest(unittest.TestCase):
    def test_detects_pdf_and_html(self):
        self.assertEqual(detect_kind(b"%PDF-1.4 ...", "application/octet-stream"), "pdf")
        self.assertEqual(detect_kind(b"<!DOCTYPE html><html>", "text/html"), "html")
        with self.assertRaises(ValueError):
            detect_kind(b"\x89PNG", "image/png")

    def test_html_to_text_drops_scripts_and_keeps_blocks(self):
        text = html_to_text(b"<html><script>x=1</script><p>Art. 1\xc2\xba Texto.</p><p>I - item;</p></html>")
        self.assertEqual(text, "Art. 1º Texto.\nI - item;")


if __name__ == "__main__":
    unittest.main()
