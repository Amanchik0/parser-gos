<?php

require 'vendor/autoload.php';

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Smalot\PdfParser\Parser;

function winnerTab($detail_link , $lotNumber)
{
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);

    echo $detail_link;
    $detailsCrawler = $browser->request('GET', "$detail_link?tab=lots");

        $rows = $detailsCrawler->filter(' .table-bordered tr ');
    if ($rows->count() === 0) {
        echo 'not found ';
        return [];

    }

//    echo $detailsCrawler->html();

    $dataWinners = [];

    $lotStartDate = $detailsCrawler->filter('.form-group input[type="text"]')->eq(4)->attr('value');
    $lotEndDate = $detailsCrawler->filter('.form-group input[type="text"]')->eq(5)->attr('value');

    if (DateTime::createFromFormat('Y-m-d H:i:s', trim($lotStartDate)) !== false) {
        $startDate = new DateTime(trim($lotStartDate));
    } else {
        $startDate = null;
    }

    if (DateTime::createFromFormat('Y-m-d H:i:s', trim($lotEndDate)) !== false) {
        $endDate = new DateTime(trim($lotEndDate));
    } else {
        $endDate = null;
    }
    echo "Start Date: $lotStartDate\n";
    echo "End Date: $lotEndDate\n";

    $rows->each(function(Crawler $row, $index) use ($endDate, $startDate, &$dataWinners) {
        if ($index === 0) {
            return;
        }

        if ($row->children()->count() < 6) {
            return;
        }

        $lotNumber = $row->filter('td')->eq(1)->text('');
        $lotTitle = $row->filter('td')->eq(3)->text('');
        $lotPricePerOne = $row->filter('td')->eq(5)->text('');
        $lotCount = $row->filter('td')->eq(6)->text('');
        $lotUnitOfMeasure = $row->filter('td')->eq(7)->text('');
        $lotPlannedAt = $row->filter('td')->eq(8)->text('');

        $dataWinners[] = [
            'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null,
            'end_date' => $endDate ? $endDate->format('Y-m-d H:i:s') : null,
            'lot_number' => trim($lotNumber),
            'lot_title' => trim($lotTitle),
            'lot_price_per_one' => trim($lotPricePerOne),
            'lot_count' => trim($lotCount),
            'lot_unit_of_measure' => trim($lotUnitOfMeasure),
            'lot_planned_at' => trim($lotPlannedAt),

        ];
    });

    return $dataWinners ;
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
            'lot_name' => $node->filter('td')->eq(1)->text(),
            'lot_link' => $node->filter('td a')->attr('href'),
        ];
    });

    if (!empty($results)) {
        foreach ($results as $result) {
            echo "Найден лот: " . $result['lot_number'] . PHP_EOL;
            echo "Наименование: " . $result['lot_name'] . PHP_EOL;
            echo "Ссылка: " . $result['lot_link'] . PHP_EOL;

            $detailsUrl = 'https://www.goszakup.gov.kz' . $result['lot_link'];
            $dataWinner = winnerTab($detailsUrl, $lotNumber);

            foreach ($dataWinner as $lot) {
                echo "lot_number: " . $lot['lot_number'] . "\n";
                echo "lotStartDate: " . $lot['start_date'] . "\n";
                echo "lotEndDate: " . $lot['end_date'] . "\n";
                echo "lot_title : " . $lot['lot_title'] . "\n";
                echo "lot_price_per_one: " . $lot['lot_price_per_one'] . "\n";
                echo "lot_count: " . $lot['lot_count'] . "\n";
                echo "lot_unit_of_measure: " . $lot['lot_unit_of_measure'] . "\n";
                echo "lot_planned_at: " . $lot['lot_planned_at'] . "\n";
                echo "----------------------------------------\n";
            }
            $dataProtocol = protocolTab($detailsUrl, $lotNumber);
            saveToCSV($dataProtocol, 'winners.csv');


        }
    } else {
        echo "Лоты не найдены." . PHP_EOL;
    }
}


function protocolTab($detail_link, $lotNumber)
{
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);
    $detailsCrawler = $browser->request('GET', "$detail_link?tab=protocols");

    $results = [];
    $links = $detailsCrawler->filter('table.table-bordered.table-stripped a.btn.btn-sm.btn-primary')->each(function ($node) {
        return $node->attr('href');
    });

    if (empty($links)) {
        echo "No protocol links found.\n";
        return [];
    }

    foreach ($links as $link) {
        echo "Processing protocol link: $link\n";

        $response = $client->request('GET', $link);

        if ($response->getStatusCode() !== 200) {
            echo "Failed to download the PDF file from $link.\n";
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

            $lines = explode("\n", trim($text));
            $startExtracting = false;
            $data = [];
            $supplierCount = 0;
            $fullLine = "";
            $supplierIndex = 0;

            // Удаляем строки с номерами страниц и заголовками
            $lines = array_filter($lines, function ($line) {
                return !preg_match('/^\d+\s*\/\s*\d+$|№Наименование поставщика|Дата и время подачи заявки/u', trim($line));
            });

            // Ищем количество поставщиков
            foreach ($lines as $line) {
                if (strpos($line, "Потенциальными поставщиками представлены следующие ценовые предложения:") !== false) {
                    if (preg_match('/\d+/', $line, $matches)) {
                        $supplierCount = (int)$matches[0];
                        echo "Количество поставщиков: $supplierCount\n";
                    }
                    break;
                }
            }

            foreach ($lines as $line) {
                $line = trim($line);

                // Начинаем обработку после заголовка таблицы
                if (strpos($line, "Потенциальными поставщиками представлены следующие ценовые предложения") !== false) {
                    $startExtracting = true;
                    continue;
                }

                if (!$startExtracting) {
                    continue;
                }

                // Если строка начинается с цифры (номер поставщика), это начало новой записи
                if (preg_match('/^\d+\s/', $line)) {
                    if (!empty($fullLine)) {
                        processSupplierLine($fullLine, $data, $supplierIndex, $supplierCount);
                        $supplierIndex++;
                    }
                    $fullLine = $line;
                } else {
                    // Объединяем разорванные строки
                    $fullLine .= " " . $line;
                }
            }

            // Обрабатываем последнюю накопленную строку
            if (!empty($fullLine)) {
                processSupplierLine($fullLine, $data, $supplierIndex, $supplierCount);
            }

            print_r($data); // Для отладки
            saveToCSV($data, 'suppliers.csv');

        } catch (Exception $e) {
            echo "Ошибка: " . $e->getMessage() . "\n";
        }

        unlink($pdfPath);
    }

    return $results;
}

// Функция обработки строки с данными поставщика
function processSupplierLine($line, &$data, $supplierIndex, $supplierCount)
{
    // Try adjusting regex to handle variations in whitespace and structure
    if (preg_match('/^(\d+)\s+"?(.*?)"?\s+(\d{12})\s+([\d\s]+)\s+([\d\s]+)\s+([\d\s]+)\s+([\d\-]+\s+[\d:.]+)$/u', $line, $matches)) {
        $data[] = [
            'number' => trim($matches[1]),
            'supplier_name' => trim($matches[2], '"'),
            'bin' => trim($matches[3]),
            'unit_price' => trim(str_replace(' ', '', $matches[4])),
            'law_price' => trim(str_replace(' ', '', $matches[5])),
            'total_price' => trim(str_replace(' ', '', $matches[6])),
            'submission_date' => trim($matches[7]),
            'supplier_count' => $supplierCount,
        ];
    } else {
        echo "Ошибка парсинга строки: $line\n"; // More detailed error
    }
}
function saveToCSV($data, $filename = 'suppliers.csv')
{
    if (empty($data)) {
        echo "Нет данных для сохранения.\n";
        return;
    }

    // Открываем файл в режиме записи (перезаписываем файл)
    $file = fopen($filename, 'w');
    if (!$file) {
        echo "Ошибка при создании CSV файла.\n";
        return;
    }

    // Записываем заголовки
    fputcsv($file, array_keys($data[0]));

    // Записываем строки
    foreach ($data as $row) {
        fputcsv($file, $row);
    }

    fclose($file);
    echo "Данные поставщиков сохранены в $filename\n";
}


$lotNumbers= ["72064302-ЗЦП1"];
foreach ($lotNumbers  as $lotNumber) {
    FindLot($lotNumber);

}
