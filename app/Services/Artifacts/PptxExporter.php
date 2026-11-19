<?php

namespace App\Services\Artifacts;

use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use RuntimeException;

/**
 * Writes a PowerPoint file of full-bleed slide pictures (RUB-562, spec 46).
 * Each slide is one PNG capture, in order, with the artifact's view URL in its
 * speaker notes. Slide text is not editable: the slides are pictures.
 */
final class PptxExporter
{
    /**
     * @param  list<string>  $captures  Raw PNG bytes, one per slide, in slide order.
     * @param  list<string>  $notes  The speaker-notes text for each slide, same order as $captures.
     * @return string Absolute path to a temporary .pptx file; the caller deletes it.
     *
     * @throws RuntimeException When a temporary file cannot be written.
     */
    public function build(array $captures, array $notes): string
    {
        $presentation = new PhpPresentation;
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $width = $presentation->getLayout()->getCX(DocumentLayout::UNIT_PIXEL);
        $height = $presentation->getLayout()->getCY(DocumentLayout::UNIT_PIXEL);

        $imagePaths = [];

        try {
            foreach ($captures as $index => $png) {
                $imagePath = $this->temporaryFile('png');
                $imagePaths[] = $imagePath;

                if (file_put_contents($imagePath, $png) === false) {
                    throw new RuntimeException('The slide capture could not be written to a temporary file.');
                }

                $slide = $index === 0 ? $presentation->getActiveSlide() : $presentation->createSlide();

                $picture = $slide->createDrawingShape();
                $picture->setName('Slide '.($index + 1));
                $picture->setPath($imagePath);
                $picture->setOffsetX(0)->setOffsetY(0);
                $picture->setWidth($width)->setHeight($height);

                $slide->getNote()->createRichTextShape()->createText($notes[$index] ?? '');
            }

            $output = $this->temporaryFile('pptx');

            IOFactory::createWriter($presentation, 'PowerPoint2007')->save($output);

            return $output;
        } finally {
            foreach ($imagePaths as $imagePath) {
                @unlink($imagePath);
            }
        }
    }

    /**
     * A new empty temporary file with the given extension.
     *
     * @throws RuntimeException
     */
    private function temporaryFile(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'artfct-pptx-');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the PowerPoint export could not be created.');
        }

        $named = $path.'.'.$extension;
        rename($path, $named);

        return $named;
    }
}
