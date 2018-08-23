<?php

namespace tests\Libero\Schemas;

use DOMDocument;
use FluentDOM;
use FluentDOM\DOM\ProcessingInstruction;
use LibXMLError;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use function Functional\map;
use function libxml_clear_errors;
use function preg_match;

final class SchemaTest extends TestCase
{
    /**
     * @before
     */
    public function clearLibXmlErrors() : void
    {
        libxml_clear_errors();
    }

    /**
     * @test
     * @dataProvider validFileProvider
     */
    public function valid_documents_pass(DOMDocument $document, string $schema) : void
    {
        $result = $document->relaxNGValidate($schema);
        $errors = $this->getLibXmlErrors();

        $this->assertTrue($result, "Document is not valid:\n".print_r($errors, true));
    }

    /**
     * @test
     * @dataProvider invalidFileProvider
     */
    public function invalid_documents_fail(DOMDocument $document, string $schema, array $expected) : void
    {
        $result = $document->relaxNGValidate($schema);
        $errors = $this->getLibXmlErrors();

        $this->assertFalse($result, 'Document is considered valid when it is not');
        $this->assertSame($expected, $errors);
    }

    public function validFileProvider() : iterable
    {
        $files = Finder::create()->files()
            ->name('*.xml')
            ->in(__DIR__)
            ->path('~/valid/~');

        return $this->extractSchemas($files);
    }

    public function invalidFileProvider() : iterable
    {
        $files = Finder::create()->files()
            ->name('*.xml')
            ->in(__DIR__)
            ->path('~/invalid/~');

        return $this->extractSchemas($files);
    }

    private function getLibXmlErrors() : array
    {
        $errors = map(
            libxml_get_errors(),
            function (LibXMLError $error) : array {
                return [
                    'line' => $error->line,
                    'message' => trim($error->message),
                ];
            }
        );

        return $errors;
    }

    private function extractSchemas(Finder $files) : iterable
    {
        foreach ($files as $file) {
            $dom = FluentDOM::load($file->getContents());

            $xmlModel = $dom('substring-before(substring-after(/processing-instruction("xml-model"), \'"\'), \'"\')');
            $schema = "{$file->getPath()}/{$xmlModel}";

            $expectedFailures = map(
                $dom('/processing-instruction("expected-error")'),
                function (ProcessingInstruction $instruction) use ($file) : array {
                    $valid = preg_match('~line="([0-9]+)"\s+message="([^"]*?)"~', $instruction->nodeValue, $matches);

                    if (!$valid) {
                        throw new LogicException(
                            'Invalid expected-error processing instruction in '.
                            $file->getRelativePathname()
                        );
                    }

                    return [
                        'line' => (int) $matches[1],
                        'message' => $matches[2],
                    ];
                }
            );

            yield $file->getRelativePathname() => [$dom, $schema, $expectedFailures];
        }
    }
}
