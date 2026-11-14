<?php

namespace App\Console\Commands;

use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Console\Command;

final class SeedGreaterCairoPlaces extends Command
{
    protected $signature = 'places:seed-greater-cairo';

    protected $description = 'Add the Greater Cairo areas, business parks and universities to the places catalogue (for a place that exists, only adds its aliases and marks it as an area).';

    /**
     * @var list<array{0: string, 1: string, 2: PlaceType, 3: float, 4: float, 5: string, 6: string, 7: list<string>}>
     */
    private const array PLACES = [
        ['New Cairo', 'القاهرة الجديدة', PlaceType::City, 30.0300, 31.4700, 'القاهرة', 'القاهرة الجديدة', ['New Cairo City', 'El Kahera El Gedida', 'القاهرة الجديده']],
        ['Fifth Settlement', 'التجمع الخامس', PlaceType::District, 30.0074, 31.4913, 'القاهرة', 'التجمع الخامس', ['التجمع', 'التجمع 5', 'tagamo3', 'tagamoa', 'tagamo3 el khames', 'el tagamoa el khames', '5th Settlement', 'Fifth District']],
        ['Rehab', 'الرحاب', PlaceType::District, 30.0620, 31.4950, 'القاهرة', 'الرحاب', ['Al Rehab', 'El Rehab', 'Rehab City', 'مدينة الرحاب']],
        ['Madinaty', 'مدينتي', PlaceType::District, 30.1070, 31.6390, 'القاهرة', 'مدينتي', ['Madinty', 'Madinaty City']],
        ['El Shorouk', 'الشروق', PlaceType::District, 30.1430, 31.6290, 'القاهرة', 'الشروق', ['Shorouk', 'Al Shorouk', 'El Sherouk', 'مدينة الشروق']],
        ['Nasr City', 'مدينة نصر', PlaceType::District, 30.0566, 31.3301, 'القاهرة', 'مدينة نصر', ['Madinet Nasr', 'Madinat Nasr', 'Nasr', 'م نصر', 'م. نصر']],
        ['Heliopolis', 'مصر الجديدة', PlaceType::District, 30.0870, 31.3250, 'القاهرة', 'مصر الجديدة', ['Masr El Gedida', 'Masr El Gdeda', 'Misr El Gedida', 'هليوبوليس']],
        ['Maadi', 'المعادي', PlaceType::District, 29.9602, 31.2569, 'القاهرة', 'المعادي', ['El Maadi', 'Al Maadi']],
        ['Downtown Cairo', 'وسط البلد', PlaceType::District, 30.0478, 31.2386, 'القاهرة', 'وسط البلد', ['West El Balad', 'Wust El Balad', 'وسط القاهرة']],
        ['Zamalek', 'الزمالك', PlaceType::District, 30.0626, 31.2197, 'القاهرة', 'الزمالك', ['El Zamalek', 'Al Zamalek']],
        ['Mohandessin', 'المهندسين', PlaceType::District, 30.0561, 31.2004, 'الجيزة', 'المهندسين', ['Mohandeseen', 'Mohandiseen', 'El Mohandessin']],
        ['Dokki', 'الدقي', PlaceType::District, 30.0385, 31.2123, 'الجيزة', 'الدقي', ['El Dokki', 'Doqqi']],
        ['Sheikh Zayed', 'الشيخ زايد', PlaceType::District, 30.0444, 30.9760, 'الجيزة', 'الشيخ زايد', ['El Sheikh Zayed', 'Shaikh Zayed', 'Zayed City', 'مدينة الشيخ زايد']],
        ['6th of October', '6 أكتوبر', PlaceType::City, 29.9285, 30.9188, 'الجيزة', '6 أكتوبر', ['6 October', '6th October', 'Sixth of October', 'October City', 'السادس من أكتوبر', 'ستة أكتوبر', 'مدينة 6 أكتوبر', 'october']],
        ['New Administrative Capital', 'العاصمة الإدارية', PlaceType::City, 30.0197, 31.7600, 'القاهرة', 'العاصمة الإدارية', ['New Capital', 'NAC', 'العاصمة الإدارية الجديدة', 'العاصمة الجديدة']],
        ['Smart Village', 'القرية الذكية', PlaceType::Office, 30.0710, 31.0170, 'الجيزة', '6 أكتوبر', ['سمارت فيليدج', 'سمارت فيلدج', 'سمارت فيلج', 'Smart Village Egypt']],
        ['Cairo Festival City', 'كايرو فستيفال سيتي', PlaceType::Office, 30.0283, 31.4085, 'القاهرة', 'القاهرة الجديدة', ['CFC', 'Festival City', 'فستيفال سيتي']],
        ['Mivida', 'ميفيدا', PlaceType::Office, 30.0105, 31.5280, 'القاهرة', 'القاهرة الجديدة', ['Mevida']],
        ['Point 90', 'بوينت 90', PlaceType::Office, 30.0207, 31.4970, 'القاهرة', 'التجمع الخامس', ['Point90', 'Point 90 Mall', 'بوينت ٩٠ مول']],
        ['Maadi Technology Park', 'القرية التكنولوجية بالمعادي', PlaceType::Office, 29.9680, 31.2840, 'القاهرة', 'المعادي', ['Maadi Tech Park', 'Technology Park Maadi']],
        ['Arkan Plaza', 'أركان بلازا', PlaceType::Office, 30.0230, 31.0040, 'الجيزة', 'الشيخ زايد', ['Arkan', 'Arkan Mall']],
        ['Downtown Mall', 'داون تاون مول', PlaceType::Office, 30.0180, 31.4300, 'القاهرة', 'القاهرة الجديدة', ['Downtown Katameya', 'داون تاون القطامية']],
        ['AUC New Cairo', 'الجامعة الأمريكية بالقاهرة', PlaceType::Campus, 30.0195, 31.5005, 'القاهرة', 'القاهرة الجديدة', ['AUC', 'American University in Cairo', 'الجامعة الأمريكية']],
        ['German University in Cairo', 'الجامعة الألمانية بالقاهرة', PlaceType::Campus, 29.9866, 31.4413, 'القاهرة', 'القاهرة الجديدة', ['GUC', 'الجامعة الألمانية']],
        ['Cairo University', 'جامعة القاهرة', PlaceType::Campus, 30.0262, 31.2088, 'الجيزة', 'الجيزة', ['CU', 'Gam3et El Qahera']],
        ['Ain Shams University', 'جامعة عين شمس', PlaceType::Campus, 30.0770, 31.2850, 'القاهرة', 'العباسية', ['ASU', 'Ain Shams', 'عين شمس']],
    ];

    public function handle(): int
    {
        $added = 0;

        foreach (self::PLACES as [$name, $nameAr, $type, $lat, $lng, $city, $district, $aliases]) {
            $place = Place::query()->firstOrCreate(['name' => $name], [
                'name_ar' => $nameAr,
                'aliases' => $aliases,
                'type' => $type,
                'point' => new Coordinate(lat: $lat, lng: $lng),
                'lat' => $lat,
                'lng' => $lng,
                'city' => $city,
                'district' => $district,
                'is_public' => true,
            ]);

            if (! $place->wasRecentlyCreated) {
                $place->fill([
                    'type' => $place->type === PlaceType::Landmark ? $type : $place->type,
                    'aliases' => array_values(array_unique([...($place->aliases ?? []), ...$aliases])),
                ])->save();
            }

            $added += $place->wasRecentlyCreated ? 1 : 0;
        }

        $this->info(sprintf('%d places added, %d already there.', $added, count(self::PLACES) - $added));

        return self::SUCCESS;
    }
}
