<?php

require 'vendor/autoload.php';

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

function parseLotTable($url) {
    $client = HttpClient::create();
    $browser = new HttpBrowser($client);

    try {
        $crawler = $browser->request('GET', $url);
    } catch (Exception $e) {
        echo "Ошибка при запросе страницы лота: " . $e->getMessage() . "\n";
        return [];
    }

    $rows = $crawler->filter('.table-bordered tr');
//echo $crawler->html();
    if ($rows->count() === 0) {
        echo "Таблица с данными лота не найдена.\n";
        return [];
    }

    $lotData = [];

    $rows->each(function(Crawler $row, $index) use (&$lotData) {
        if ($index === 0) {
            return;
        }

        if ($row->children()->count() < 6) {
            return;
        }

        $lotNumber = $row->filter('td')->eq(0)->text('');
        $lotName = $row->filter('td')->eq(1)->text('');
        $plannedAmount = $row->filter('td')->eq(2)->text('');
        $lotStatus = $row->filter('td')->eq(3)->text('');
        $winner = $row->filter('td')->eq(4)->text('');
        $secondPlaceSupplier = $row->filter('td')->eq(5)->text('');

        $lotData[] = [
            'lot_number' => trim($lotNumber),
            'lot_name' => trim($lotName),
            'planned_amount' => trim($plannedAmount),
            'lot_status' => trim($lotStatus),
            'winner' => trim($winner),
            'second_place_supplier' => trim($secondPlaceSupplier)
        ];
    });

    return $lotData;
}





function saveToCSV($data, $filename = 'lots.csv') {
    if (empty($data)) {
        echo "Нет данных для записи в CSV.\n";
        return;
    }

    $file = fopen($filename, 'a');
    if (ftell($file) === 0) {
        fputcsv($file, ['Lot Number', 'Lot Name', 'Planned Amount', 'Lot Status', 'Winner', 'Second Place Supplier']);
    }

    // Записываем данные
    foreach ($data as $row) {
        fputcsv($file, $row);
    }
    fclose($file);
}

$filename='lots.csv';
$file = file_put_contents($filename, '');


$lotNumber = [13749426, 13763682 ] ;
//href="https://v3bl.goszakup.gov.kz/files/download_file/257132600/"
foreach ($lotNumber as $lotNumber) {
    echo $lotNumber . PHP_EOL;
    $lotUrl = "https://www.goszakup.gov.kz/ru/announce/index/$lotNumber?tab=winners";
    $lotData = parseLotTable($lotUrl);
    foreach ($lotData as $lot) {
        echo "Номер лота: " . $lot['lot_number'] . "\n";
        echo "Наименование лота: " . $lot['lot_name'] . "\n";
        echo "Плановая сумма лота: " . $lot['planned_amount'] . "\n";
        echo "Статус лота: " . $lot['lot_status'] . "\n";
        echo "Победитель: " . $lot['winner'] . "\n";
        echo "Поставщик, занявший второе место: " . $lot['second_place_supplier'] . "\n";
        echo "----------------------------------------\n";
    }
    saveToCSV($lotData);

}




echo "Данные для лота $lotNumber сохранены в CSV.\n";
