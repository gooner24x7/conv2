<?php require 'vendor/autoload.php'; \ = new \BoqAllocator\Services\BoqParserService(); \ = \->parseCsv('templates/NRM1 template.csv'); print_r(array_slice(\['Sheet1'], 0, 5));
