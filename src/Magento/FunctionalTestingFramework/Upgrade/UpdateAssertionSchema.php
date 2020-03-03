<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\Upgrade;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Class UpdateAssertionSchema
 * @package Magento\FunctionalTestingFramework\Upgrade
 */
class UpdateAssertionSchema implements UpgradeInterface
{
    const OLD_ASSERTION_ATTRIBUTES = ["expected", "expectedType", "actual", "actualType"];

    /**
     * Current file being inspected, for error messaging
     * @var string
     */
    private $currentFile;

    /**
     * Potential errors reported during replacement.
     * @var array
     */
    private $errors = [];

    /**
     * Upgrades all test xml files, changing as many <assert> actions to be nested as possible
     * WILL NOT CATCH cases where style is a mix of old and new
     *
     * @param InputInterface $input
     * @return string
     */
    public function execute(InputInterface $input)
    {
        $testsPath = $input->getArgument('path');
        $finder = new Finder();
        $finder->files()->in($testsPath)->name("*.xml");

        $fileSystem = new Filesystem();
        $testsUpdated = 0;
        foreach ($finder->files() as $file) {
            $this->currentFile = $file->getFilename();
            $contents = $file->getContents();
            // Isolate <assert ... /> but never <assert> ... </assert>
            preg_match_all('/<assert[^>]*\/>/', $contents, $potentialAssertions);
            $newAssertions = [];
            $index = 0;
            if (empty($potentialAssertions[0])) {
                continue;
            }
            foreach ($potentialAssertions[0] as $potentialAssertion) {
                $newAssertions[$index] = $this->convertOldAssertionToNew($potentialAssertion);
                $index++;
            }
            foreach ($newAssertions as $currentIndex => $replacements) {
                $contents = str_replace($potentialAssertions[0][$currentIndex], $replacements, $contents);
            }
            $fileSystem->dumpFile($file->getRealPath(), $contents);
            $testsUpdated++;
        }

        return ("Assertion Syntax updated in {$testsUpdated} file(s).\n" . implode("\n\t", $this->errors));
    }

    /**
     * Detects present of attributes in file
     *
     * @param string $file
     * @return boolean
     */
    private function detectOldAttributes($file)
    {
        foreach (self::OLD_ASSERTION_ATTRIBUTES as $OLD_ASSERTION_ATTRIBUTE) {
            if (strpos($file->getContents(), $OLD_ASSERTION_ATTRIBUTE) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Takes given string and attempts to convert it from single line to multi-line
     *
     * @param string $assertion
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function convertOldAssertionToNew($assertion)
    {
        // <assertSomething => assertSomething
        $assertType = ltrim(explode(' ', $assertion)[0], '<');

        // regex to grab values
        $grabValueRegex = '/(stepKey|actual|actualType|expected|expectedType|delta|message|selector|attribute|expectedValue|before|after|remove)=(\'[^\']*\'|"[^"]*")/';
        // Make 3 arrays in $grabbedParts:
        // 0 contains stepKey="value"
        // 1 contains stepKey
        // 2 contains value
        $sortedParts = [];
        preg_match_all($grabValueRegex, $assertion, $grabbedParts);
        for ($i = 0; $i < count($grabbedParts[0]); $i++) {
            $sortedParts[$grabbedParts[1][$i]] = $grabbedParts[2][$i];
        }

        // Build new String, trim ' and "
        $trimmedParts = [];
        $newString = "<$assertType";
        $subElements = ["actual" => [], "expected" => []];
        foreach ($sortedParts as $type => $value) {
            if (strpos($value, '"') === 0) {
                $value = rtrim(ltrim($value, '"'), '"');
            } elseif(strpos($value, "'") === 0) {
                $value = rtrim(ltrim($value, "'"), "'");
            }
            // If value is empty string, trim again
            if (str_replace(" ", "", $value) == "''") {
                $value = "";
            } elseif (str_replace(" ", "", $value) == '""') {
                $value = "";
            }
            $trimmedParts[$type] = $value;
            if (in_array($type, ["stepKey", "delta", "message", "before", "after", "remove"])) {
                $newString .= " $type=\"$value\"";
                continue;
            }
            if ($type == "actual") {
                $subElements["actual"]["value"] = $value;
            } elseif ($type == "actualType") {
                $subElements["actual"]["type"] = $value;
            } elseif ($type == "expected" or $type == "expectedValue") {
                $subElements["expected"]["value"] = $value;
            } elseif ($type == "expectedType") {
                $subElements["expected"]["type"] = $value;
            }
        }
        $newString .= ">\n";
        // Set type to const if it's absent
        if (isset($subElements["actual"]['value']) && !isset($subElements["actual"]['type'])) {
            $subElements["actual"]['type'] = "const";
        }
        if (isset($subElements["expected"]['value']) && !isset($subElements["expected"]['type'])) {
            $subElements["expected"]['type'] = "const";
        }
        // Massage subElements with data for edge case
        if ($assertType == 'assertElementContainsAttribute') {
            // Assert type is very edge-cased, completely different schema
            $value = $subElements['expected']['value'] ?? "";
            $selector = $trimmedParts['selector'];
            $attribute = $trimmedParts['attribute'];
            $newString .= "\t\t\t<expectedResult selector=\"$selector\" attribute=\"$attribute\">$value</expectedResult>\n";
        } else {
            foreach ($subElements as $type => $subElement) {
                if (empty($subElement)) {
                    continue;
                }
                $value = $subElement['value'];
                $typeValue = $subElement['type'];
                $newString .= "\t\t\t<{$type}Result type=\"$typeValue\">$value</{$type}Result>\n";
            }
        }
        $newString .= "        </$assertType>";
        return $newString;
    }
}
