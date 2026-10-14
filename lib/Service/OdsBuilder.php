<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

/**
 * Multi-sheet ODS writer — ODF 1.3 spreadsheet assembled via ZipArchive.
 * Pure formatter: takes [{name, columns: [header...], rows: [[cell...]]}]
 * and returns the binary package. No formula-injection concern: ODS
 * formulas are declared via table:formula attributes, never inferred
 * from cell text (same guarantee as the PrivacyCheck writer this follows).
 */
class OdsBuilder
{
	private const ODS_MIME = 'application/vnd.oasis.opendocument.spreadsheet';

	/**
	 * @param list<array{name: string, columns: list<string>, rows: list<list<mixed>>}> $sheets
	 */
	public function build(array $sheets, string $generator = 'PrivacyCheck'): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'tck-ods-');
		if ($tmp === false) {
			throw new \RuntimeException('Could not create a temporary file for the export.');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('Could not build the export archive.');
			}
			// ODF requires "mimetype" first and STORED so readers can sniff
			// the type without inflating the archive.
			$zip->addFromString('mimetype', self::ODS_MIME);
			$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
			$zip->addFromString('META-INF/manifest.xml', $this->odsManifest());
			$zip->addFromString('meta.xml', $this->odsMeta($generator));
			$zip->addFromString('styles.xml', $this->odsStyles());
			$zip->addFromString('content.xml', $this->odsContent($sheets));
			$zip->close();
			$content = file_get_contents($tmp);
			if ($content === false) {
				throw new \RuntimeException('Could not read the export archive.');
			}
			return $content;
		} finally {
			@unlink($tmp);
		}
	}

	private function odsManifest(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . self::ODS_MIME . '"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>';
	}

	private function odsMeta(string $generator): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:version="1.3">'
			. '<office:meta><meta:generator>' . $this->xml($generator) . '</meta:generator>'
			. '<meta:creation-date>' . gmdate('Y-m-d\TH:i:s\Z') . '</meta:creation-date>'
			. '</office:meta></office:document-meta>';
	}

	private function odsStyles(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
			. 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.3">'
			. '<office:styles>'
			. '<style:style style:name="ceHeader" style:family="table-cell">'
			. '<style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/>'
			. '</style:style>'
			. '</office:styles></office:document-styles>';
	}

	/** @param list<array{name: string, columns: list<string>, rows: list<list<mixed>>}> $sheets */
	private function odsContent(array $sheets): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-content '
			. 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
			. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
			. 'office:version="1.3">'
			. '<office:automatic-styles/>'
			. '<office:body><office:spreadsheet>';
		foreach ($sheets as $sheet) {
			// ODF table names: 31-char limit like spreadsheet apps, no spaces/
			// quotes — normalize so a sheet can never break the package.
			$name = $this->sheetName($sheet['name']);
			$cols = max(1, count($sheet['columns']));
			$xml .= '<table:table table:name="' . $this->xml($name) . '">'
				. '<table:table-column table:number-columns-repeated="' . $cols . '"/>'
				. '<table:table-row>';
			foreach ($sheet['columns'] as $col) {
				$xml .= '<table:table-cell table:style-name="ceHeader" office:value-type="string"><text:p>'
					. $this->xmlText($col) . '</text:p></table:table-cell>';
			}
			$xml .= '</table:table-row>';
			foreach ($sheet['rows'] as $cells) {
				$xml .= '<table:table-row>';
				foreach ($cells as $cell) {
					$xml .= $this->odsCell($cell);
				}
				$xml .= '</table:table-row>';
			}
			$xml .= '</table:table>';
		}
		return $xml . '</office:spreadsheet></office:body></office:document-content>';
	}

	private function odsCell(mixed $cell): string
	{
		if ($cell === null) {
			return '<table:table-cell office:value-type="string"><text:p></text:p></table:table-cell>';
		}
		if (is_bool($cell)) {
			return '<table:table-cell office:value-type="boolean" office:boolean-value="'
				. ($cell ? 'true' : 'false') . '"><text:p>' . ($cell ? 'TRUE' : 'FALSE') . '</text:p></table:table-cell>';
		}
		if (is_int($cell) || is_float($cell)) {
			return '<table:table-cell office:value-type="float" office:value="'
				. $cell . '"><text:p>' . $cell . '</text:p></table:table-cell>';
		}
		return '<table:table-cell office:value-type="string"><text:p>' . $this->xmlText((string)$cell) . '</text:p></table:table-cell>';
	}

	private function sheetName(string $name): string
	{
		$name = preg_replace('/[\\\\\'"\\[\\]:*?\\/\\x00-\\x1f]/u', '-', $name) ?? $name;
		$name = mb_substr(trim($name), 0, 31);
		return $name === '' ? 'Sheet' : $name;
	}

	private function xml(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/** Xml-escape and strip control characters ODF 1.3 forbids in text nodes. */
	private function xmlText(string $s): string
	{
		$s = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', '', $s) ?? '';
		return $this->xml($s);
	}
}
