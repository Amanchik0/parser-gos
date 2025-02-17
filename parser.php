<?php
require 'vendor/autoload.php';

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Smalot\PdfParser\Parser;


function winnerTab($detail_link, $lotNumber)
{
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);

    echo $detail_link . "\n";
    $detailsCrawler = $browser->request('GET', "$detail_link?tab=lots");

    $rows = $detailsCrawler->filter('.table-bordered tr');
    if ($rows->count() === 0) {
        echo "Лоты не найдены\n";
        return [];
    }

    $dataWinners = [];

    $lotStartDate = $detailsCrawler->filter('.form-group input[type="text"]')->eq(4)->attr('value');
    $lotEndDate   = $detailsCrawler->filter('.form-group input[type="text"]')->eq(5)->attr('value');

    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', trim($lotStartDate)) ? new DateTime(trim($lotStartDate)) : null;
    $endDate   = DateTime::createFromFormat('Y-m-d H:i:s', trim($lotEndDate))   ? new DateTime(trim($lotEndDate))   : null;

    echo "Start Date: $lotStartDate\n";
    echo "End Date: $lotEndDate\n";

    $rows->each(function(Crawler $row, $index) use ($startDate, $endDate, &$dataWinners) {
        if ($index === 0) {
            return;
        }
        if ($row->children()->count() < 6) {
            return;
        }
        $lotNumberText    = $row->filter('td')->eq(1)->text('');
        $lotTitle         = $row->filter('td')->eq(3)->text('');
        $lotPricePerOne   = $row->filter('td')->eq(5)->text('');
        $lotCount         = $row->filter('td')->eq(6)->text('');
        $lotUnitOfMeasure = $row->filter('td')->eq(7)->text('');
        $lotPlannedAt     = $row->filter('td')->eq(8)->text('');

        $dataWinners[] = [
            'start_date'          => $startDate ? $startDate->format('Y-m-d H:i:s') : null,
            'end_date'            => $endDate ? $endDate->format('Y-m-d H:i:s') : null,
            'lot_number'          => trim($lotNumberText),
            'lot_title'           => trim($lotTitle),
            'lot_price_per_one'   => trim($lotPricePerOne),
            'lot_count'           => trim($lotCount),
            'lot_unit_of_measure' => trim($lotUnitOfMeasure),
            'lot_planned_at'      => trim($lotPlannedAt),
        ];
    });

    return $dataWinners;
}

function protocolTab($detail_link, $lotNumber)
{
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);
    $detailsCrawler = $browser->request('GET', "$detail_link?tab=protocols");

    $links = $detailsCrawler->filter('table.table-bordered.table-stripped a.btn.btn-sm.btn-primary')
        ->each(function ($node) {
            return $node->attr('href');
        });

    if (empty($links)) {
        echo "No protocol links found.\n";
        return [];
    }

    $results = [];
    foreach ($links as $link) {
        echo "Processing protocol link: $link\n";
        $response = $client->request('GET', $link);
        if ($response->getStatusCode() !== 200) {
            echo "Failed to download PDF from $link\n";
            continue;
        }

        $pdfPath = __DIR__ . '/result_' . uniqid() . '.pdf';
        file_put_contents($pdfPath, $response->getContent());
        echo "PDF saved to $pdfPath\n";

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            file_put_contents(__DIR__ . '/debug_parsed.txt', $text);
            echo "Parsed text saved to debug_parsed.txt\n";

            if (preg_match('/Әлеуетті өнім берушілер.*ұсынды:\s*\d+\s*(.+)$/us', $text, $m)) {
                $supplierBlock = $m[1];
            } else {
                $supplierBlock = $text;
            }

            $lines = preg_split('/\r\n|\r|\n/', $supplierBlock);
            $filteredLines = array_filter($lines, function($line) {
                return !preg_match('/^\s*\d+\s*\/\s*\d+/', $line);
            });

            $records = [];
            $currentRecord = '';
            $recordStarted = false;
            foreach ($filteredLines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (preg_match('/^\d+\s*(?:"|ИП)/u', $line)) {
                    if (!empty($currentRecord)) {
                        $records[] = $currentRecord;
                    }
                    $currentRecord = $line;
                    $recordStarted = true;
                } else {
                    if ($recordStarted) {
                        $currentRecord .= ' ' . $line;
                    }
                }
            }
            if (!empty($currentRecord)) {
                $records[] = $currentRecord;
            }

            $desired = array_slice($records, 0, 2);
            $suppliers = [];

            $pattern = '/^\s*(\d+)\s*((?:"[^"]+"\s*|ИП\s+[^"]+\s*)?.+?)\s+(\d{12})\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?)/u';

            foreach ($desired as $record) {
                if (preg_match($pattern, $record, $match)) {
                    $suppliers[] = [
                        'number'          => count($suppliers) + 1,
                        'supplier_name'   => trim($match[2]),
                        'bin'             => trim($match[3]),
                        'unit_price'      => trim($match[4]),
                        'law_price'       => trim($match[5]),
                        'total_price'     => trim($match[6]),
                        'submission_date' => trim($match[7]),
                        'supplier_count'  => '-'
                    ];
                } else {
                    echo "Record did not match pattern: $record\n";
                }
            }

            print_r($suppliers);
            saveToCSV($suppliers, 'suppliers.csv');
            $results = $suppliers;
        } catch (Exception $e) {
            echo "Ошибка: " . $e->getMessage() . "\n";
        }
        unlink($pdfPath);
    }
    return $results;
}


function FindLot($lotNumber)
{
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);

    $crawler = $browser->request('GET', 'https://www.goszakup.gov.kz/ru/search/lots/');
    $form = $crawler->selectButton('Поиск')->form();
    $form['filter[number]'] = $lotNumber;
    $responseCrawler = $browser->submit($form);

    $results = $responseCrawler->filter('table#search-result tbody tr')->each(function (Crawler $node) {
        return [
            'lot_number' => $node->filter('td')->eq(0)->text(),
            'lot_name'   => $node->filter('td')->eq(1)->text(),
            'lot_link'   => $node->filter('td a')->attr('href'),
        ];
    });

    $combinedRows = [];
    if (!empty($results)) {
        foreach ($results as $result) {
            echo "Найден лот: " . $result['lot_number'] . "\n";
            echo "Наименование: " . $result['lot_name'] . "\n";
            echo "Ссылка: " . $result['lot_link'] . "\n";

            $detailsUrl = 'https://www.goszakup.gov.kz' . $result['lot_link'];

            $dataWinner = winnerTab($detailsUrl, $lotNumber);
            if (!empty($dataWinner)) {
                $lotDataRaw = $dataWinner[0];
                $lotData = [
                    'lot_number'          => $lotDataRaw['lot_number'],
                    'lotEndDate'          => $lotDataRaw['end_date'],
                    'lot_title'           => $lotDataRaw['lot_title'],
                    'lot_price_per_one'   => $lotDataRaw['lot_price_per_one'],
                    'lot_count'           => $lotDataRaw['lot_count'],
                    'lot_unit_of_measure' => $lotDataRaw['lot_unit_of_measure'],
                    'lot_planned_at'      => $lotDataRaw['lot_planned_at']
                ];
                echo "Данные лота:\n";
                print_r($lotData);
            } else {
                echo "Данные лота не найдены.\n";
                continue;
            }

            $dataProtocol = protocolTab($detailsUrl, $lotNumber);

            $submittedBids = '-';
            $debugFile = __DIR__ . '/debug_parsed.txt';
            if (file_exists($debugFile)) {
                $debugText = file_get_contents($debugFile);
                if (preg_match('/Әлеуетті өнім берушілер.*ұсынды:\s*(\d+)/u', $debugText, $m)) {
                    $submittedBids = $m[1];
                }
            }

            $combinedData = combineDataToCSV($lotData, $submittedBids, $dataProtocol);
            if (!empty($combinedData)) {
                $combinedRows[] = $combinedData;
            }

            saveToCSV($dataProtocol, 'suppliers.csv');
        }
    } else {
        echo "Лоты не найдены.\n";
    }
    return $combinedRows;
}


function saveToCSV($data, $filename)
{
    if (empty($data)) {
        echo "Нет данных для сохранения.\n";
        return;
    }
    $file = fopen($filename, 'w');
    if (!$file) {
        echo "Ошибка при создании CSV файла.\n";
        return;
    }
    fputcsv($file, array_keys($data[0]), "\t");
    foreach ($data as $row) {
        fputcsv($file, $row, "\t");
    }
    fclose($file);
    echo "Данные сохранены в $filename\n";
}


function combineDataToCSV($lotData, $submittedBids, $supplierOffers)
{
    usort($supplierOffers, function($a, $b) {
        $priceA = (float)str_replace([' ', ','], ['', '.'], $a['unit_price']);
        $priceB = (float)str_replace([' ', ','], ['', '.'], $b['unit_price']);
        return $priceA - $priceB;
    });

    if (count($supplierOffers) < 2) {
        echo "Недостаточно данных поставщиков для определения победителя и второго места.\n";
        return [];
    }

    $winner = $supplierOffers[0];
    $second = $supplierOffers[1];

    $planned = (float)str_replace([' ', ','], ['', '.'], $lotData['lot_planned_at']);
    $winnerTotal = (float)$winner['total_price'];
    $percentage = ($planned > 0) ? round(($winnerTotal / $planned) * 100, 2) : 0;

    $combined = [
        'Номер лота'                              => $lotData['lot_number'] ?? '-',
        'Срок окончания приема заявок'            => $lotData['lotEndDate'] ?? '-',
        'Кол-во поданных заявок'                   => $submittedBids,
        'Категория'                               => '-',
        'Наименование лота'                        => $lotData['lot_title'] ?? '-',
        'Цена за ед.'                             => $lotData['lot_price_per_one'] ?? '-',
        'Кол-во'                                  => $lotData['lot_count'] ?? '-',
        'Ед. изм.'                                => $lotData['lot_unit_of_measure'] ?? '-',
        'Плановая сумма'                          => $lotData['lot_planned_at'] ?? '-',
        'Победитель'                              => $winner['supplier_name'] ?? '-',
        'БИН (ИИН)'                               => $winner['bin'] ?? '-',
        'Цена за единицу'                         => $winner['unit_price'] ?? '-',
        'Общая сумма поставщика'                   => $winner['total_price'] ?? '-',
        'Ұтқан баға берілген бағаның қанша проценті' => $percentage,
        'Телефон номер'                           => '-',
        'Второе место'                            => $second['supplier_name'] ?? '-',
        'БИН (ИИН) (2)'                           => $second['bin'] ?? '-'
    ];

    return $combined;
}


function saveCombinedCSV($data, $filename = 'combined.csv')
{
    if (empty($data)) {
        echo "Нет объединённых данных для сохранения.\n";
        return;
    }
    $file = fopen($filename, 'w');
    if (!$file) {
        echo "Ошибка при создании CSV файла.\n";
        return;
    }
    fputcsv($file, array_keys($data[0]), "\t");
    foreach ($data as $row) {
        fputcsv($file, $row, "\t");
    }
    fclose($file);
    echo "Объединённые данные сохранены в $filename\n";
}

$lotNumbers = ["72064302-ЗЦП1", "75017320-ЗЦП1"];
$allCombinedRows = [];
foreach ($lotNumbers as $lotNumber) {
    $combinedRows = FindLot($lotNumber);
    if (!empty($combinedRows)) {
        $allCombinedRows = array_merge($allCombinedRows, $combinedRows);
    }
}

saveCombinedCSV($allCombinedRows, 'combined.csv');

