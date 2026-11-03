<?php

namespace App\Console\Commands;

use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Console\Command;

final class SeedGreaterCairoPlaces extends Command
{
    protected $signature = 'places:seed-greater-cairo';

    protected $description = 'Add the Greater Cairo areas, business parks and universities to the places catalogue (skips any that exist).';

    /**
     * @var list<array{0: string, 1: string, 2: PlaceType, 3: float, 4: float, 5: string, 6: string}>
     */
    private const array PLACES = [
        ['New Cairo', 'القاهرة الجديدة', PlaceType::Landmark, 30.0300, 31.4700, 'القاهرة', 'القاهرة الجديدة'],
        ['Fifth Settlement', 'التجمع الخامس', PlaceType::Landmark, 30.0074, 31.4913, 'القاهرة', 'التجمع الخامس'],
        ['Rehab', 'الرحاب', PlaceType::Landmark, 30.0620, 31.4950, 'القاهرة', 'الرحاب'],
        ['Madinaty', 'مدينتي', PlaceType::Landmark, 30.1070, 31.6390, 'القاهرة', 'مدينتي'],
        ['El Shorouk', 'الشروق', PlaceType::Landmark, 30.1430, 31.6290, 'القاهرة', 'الشروق'],
        ['Nasr City', 'مدينة نصر', PlaceType::Landmark, 30.0566, 31.3301, 'القاهرة', 'مدينة نصر'],
        ['Heliopolis', 'مصر الجديدة', PlaceType::Landmark, 30.0870, 31.3250, 'القاهرة', 'مصر الجديدة'],
        ['Maadi', 'المعادي', PlaceType::Landmark, 29.9602, 31.2569, 'القاهرة', 'المعادي'],
        ['Downtown Cairo', 'وسط البلد', PlaceType::Landmark, 30.0478, 31.2386, 'القاهرة', 'وسط البلد'],
        ['Zamalek', 'الزمالك', PlaceType::Landmark, 30.0626, 31.2197, 'القاهرة', 'الزمالك'],
        ['Mohandessin', 'المهندسين', PlaceType::Landmark, 30.0561, 31.2004, 'الجيزة', 'المهندسين'],
        ['Dokki', 'الدقي', PlaceType::Landmark, 30.0385, 31.2123, 'الجيزة', 'الدقي'],
        ['Sheikh Zayed', 'الشيخ زايد', PlaceType::Landmark, 30.0444, 30.9760, 'الجيزة', 'الشيخ زايد'],
        ['6th of October', '6 أكتوبر', PlaceType::Landmark, 29.9285, 30.9188, 'الجيزة', '6 أكتوبر'],
        ['New Administrative Capital', 'العاصمة الإدارية', PlaceType::Landmark, 30.0197, 31.7600, 'القاهرة', 'العاصمة الإدارية'],
        ['Smart Village', 'القرية الذكية', PlaceType::Office, 30.0710, 31.0170, 'الجيزة', '6 أكتوبر'],
        ['Cairo Festival City', 'كايرو فستيفال سيتي', PlaceType::Office, 30.0283, 31.4085, 'القاهرة', 'القاهرة الجديدة'],
        ['Mivida', 'ميفيدا', PlaceType::Office, 30.0105, 31.5280, 'القاهرة', 'القاهرة الجديدة'],
        ['Point 90', 'بوينت 90', PlaceType::Office, 30.0207, 31.4970, 'القاهرة', 'التجمع الخامس'],
        ['Maadi Technology Park', 'القرية التكنولوجية بالمعادي', PlaceType::Office, 29.9680, 31.2840, 'القاهرة', 'المعادي'],
        ['Arkan Plaza', 'أركان بلازا', PlaceType::Office, 30.0230, 31.0040, 'الجيزة', 'الشيخ زايد'],
        ['Downtown Mall', 'داون تاون مول', PlaceType::Office, 30.0180, 31.4300, 'القاهرة', 'القاهرة الجديدة'],
        ['AUC New Cairo', 'الجامعة الأمريكية بالقاهرة', PlaceType::Campus, 30.0195, 31.5005, 'القاهرة', 'القاهرة الجديدة'],
        ['German University in Cairo', 'الجامعة الألمانية بالقاهرة', PlaceType::Campus, 29.9866, 31.4413, 'القاهرة', 'القاهرة الجديدة'],
        ['Cairo University', 'جامعة القاهرة', PlaceType::Campus, 30.0262, 31.2088, 'الجيزة', 'الجيزة'],
        ['Ain Shams University', 'جامعة عين شمس', PlaceType::Campus, 30.0770, 31.2850, 'القاهرة', 'العباسية'],
    ];

    public function handle(): int
    {
        $added = 0;

        foreach (self::PLACES as [$name, $nameAr, $type, $lat, $lng, $city, $district]) {
            $place = Place::query()->firstOrCreate(['name' => $name], [
                'name_ar' => $nameAr,
                'type' => $type,
                'point' => new Coordinate(lat: $lat, lng: $lng),
                'lat' => $lat,
                'lng' => $lng,
                'city' => $city,
                'district' => $district,
                'is_public' => true,
            ]);

            $added += $place->wasRecentlyCreated ? 1 : 0;
        }

        $this->info(sprintf('%d places added, %d already there.', $added, count(self::PLACES) - $added));

        return self::SUCCESS;
    }
}
