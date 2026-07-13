<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NepalBranchProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $now = now();

        $mainBranchId = $this->upsertBranch([
            'code' => 'NP-KTM-MAIN',
            'name' => 'Kathmandu Main Branch',
            'type' => 'main_branch',
            'phone' => '015970001',
            'email' => 'main.kathmandu@tukaatuexpress.com',
            'city' => 'Kathmandu',
            'area' => 'Tripureshwor',
            'address' => 'Tripureshwor, Kathmandu, Bagmati Province',
            'latitude' => 27.7172,
            'longitude' => 85.3240,
            'status' => 'active',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($this->districtsWithCities() as $district => $cities) {
            $districtActive = $this->isActiveProductionHub($district, $cities[0] ?? null);
            $districtCoordinates = $this->coordinatesFor($district, $cities[0] ?? null);

            $districtBranchId = $this->upsertBranch([
                'parent_id' => $mainBranchId,
                'code' => $this->branchCode($district, 'BR'),
                'name' => $district . ' Branch',
                'type' => 'branch',
                'phone' => $this->phoneFromText($district),
                'email' => Str::slug($district) . '.branch@tukaatuexpress.com',
                'city' => $district,
                'area' => $cities[0] ?? $district,
                'address' => ($cities[0] ?? $district) . ', ' . $district . ', Nepal',
                'latitude' => $districtActive ? $districtCoordinates['latitude'] : null,
                'longitude' => $districtActive ? $districtCoordinates['longitude'] : null,
                'status' => $districtActive ? 'active' : 'inactive',
                'is_active' => $districtActive,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $cities = array_values(array_unique(array_merge($cities, [$district . ' Bazaar'])));

            foreach ($cities as $city) {
                $subActive = $districtActive;
                $subCoordinates = $this->coordinatesFor($district, $city);

                $subBranchId = $this->upsertBranch([
                    'parent_id' => $districtBranchId,
                    'code' => $this->branchCode($district . ' ' . $city, 'SB'),
                    'name' => $city . ' Sub-Branch',
                    'type' => 'sub_branch',
                    'phone' => $this->phoneFromText($district . $city),
                    'email' => Str::slug($district . '-' . $city) . '.sub@tukaatuexpress.com',
                    'city' => $district,
                    'area' => $city,
                    'address' => $city . ', ' . $district . ', Nepal',
                    'latitude' => $subActive ? $subCoordinates['latitude'] : null,
                    'longitude' => $subActive ? $subCoordinates['longitude'] : null,
                    'status' => $subActive ? 'active' : 'inactive',
                    'is_active' => $subActive,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->upsertServiceArea($districtBranchId, $district, $city, $subActive);
            }
        }

        $this->command?->info('Production Nepal branches seeded successfully.');
        $this->command?->info('Total branches: ' . DB::table('branches')->count());
        $this->command?->info('Active branches: ' . DB::table('branches')->where('status', 'active')->count());
        $this->command?->info('Inactive branches: ' . DB::table('branches')->where('status', 'inactive')->count());
    }

    private function upsertBranch(array $data): int
    {
        $code = $data['code'];

        DB::table('branches')->updateOrInsert(
            ['code' => $code],
            $this->cols('branches', $data)
        );

        return (int) DB::table('branches')->where('code', $code)->value('id');
    }

    private function upsertServiceArea(int $branchId, string $city, string $area, bool $active): void
    {
        if (!Schema::hasTable('branch_service_areas')) {
            return;
        }

        DB::table('branch_service_areas')->updateOrInsert(
            [
                'branch_id' => $branchId,
                'city' => $city,
                'area' => $area,
            ],
            $this->cols('branch_service_areas', [
                'branch_id' => $branchId,
                'city' => $city,
                'area' => $area,
                'postal_code' => null,
                'status' => $active ? 'active' : 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    private function isActiveProductionHub(string $district, ?string $headquarter = null): bool
    {
        $district = Str::lower($district);
        $headquarter = Str::lower((string) $headquarter);

        $activeKeywords = [
            'kathmandu',
            'lalitpur',
            'bhaktapur',
            'kaski',
            'pokhara',
            'morang',
            'biratnagar',
            'dhading',
            'bhading',
            'chitwan',
            'bharatpur',
            'rupandehi',
            'butwal',
            'bhairahawa',
            'parsa',
            'birgunj',
            'banke',
            'nepalgunj',
            'sunsari',
            'itahari',
            'dharan',
            'kailali',
            'dhangadhi',
        ];

        foreach ($activeKeywords as $keyword) {
            if (str_contains($district, $keyword) || str_contains($headquarter, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function coordinatesFor(string $district, ?string $area = null): array
    {
        $key = Str::lower(trim($district . ' ' . (string) $area));

        $map = [
            'kathmandu tripureshwor' => [27.6954, 85.3143],
            'kathmandu new road' => [27.7041, 85.3124],
            'kathmandu baneshwor' => [27.6880, 85.3350],
            'kathmandu kalanki' => [27.6931, 85.2814],
            'kathmandu chabahil' => [27.7167, 85.3463],
            'kathmandu koteshwor' => [27.6785, 85.3493],
            'kathmandu balaju' => [27.7350, 85.3047],
            'kathmandu kathmandu bazaar' => [27.7172, 85.3240],

            'lalitpur patan' => [27.6727, 85.3253],
            'lalitpur jawalakhel' => [27.6720, 85.3134],
            'lalitpur lagankhel' => [27.6666, 85.3230],
            'lalitpur satdobato' => [27.6588, 85.3247],
            'lalitpur lalitpur bazaar' => [27.6727, 85.3253],

            'bhaktapur bhaktapur durbar square' => [27.6722, 85.4288],
            'bhaktapur suryabinayak' => [27.6651, 85.4278],
            'bhaktapur thimi' => [27.6806, 85.3863],
            'bhaktapur duwakot' => [27.7092, 85.4152],
            'bhaktapur bhaktapur bazaar' => [27.6710, 85.4298],

            'kaski pokhara' => [28.2096, 83.9856],
            'kaski lakeside' => [28.2092, 83.9585],
            'kaski prithvi chowk' => [28.2134, 83.9948],
            'kaski bagar' => [28.2366, 83.9956],
            'kaski kaski bazaar' => [28.2096, 83.9856],

            'morang biratnagar' => [26.4525, 87.2718],
            'morang urlabari' => [26.6700, 87.6100],
            'morang belbari' => [26.6655, 87.4291],
            'morang pathari' => [26.6497, 87.5583],
            'morang morang bazaar' => [26.4525, 87.2718],

            'dhading dhading besi' => [27.9711, 84.8985],
            'dhading malekhu' => [27.8186, 84.7933],
            'dhading gajuri' => [27.7833, 84.7500],
            'dhading dhading bazaar' => [27.9711, 84.8985],

            'chitwan bharatpur' => [27.6768, 84.4359],
            'chitwan narayanghat' => [27.7000, 84.4167],
            'chitwan tandi' => [27.6200, 84.5000],
            'chitwan mugling' => [27.8500, 84.5500],
            'chitwan chitwan bazaar' => [27.6768, 84.4359],

            'rupandehi butwal' => [27.7006, 83.4484],
            'rupandehi bhairahawa' => [27.5050, 83.4163],
            'rupandehi tilottama' => [27.6200, 83.4700],
            'rupandehi devdaha' => [27.6700, 83.5400],
            'rupandehi rupandehi bazaar' => [27.7006, 83.4484],

            'parsa birgunj' => [27.0104, 84.8774],
            'parsa pokhariya' => [27.0500, 84.7200],
            'parsa thori' => [27.3000, 84.6000],
            'parsa parsa bazaar' => [27.0104, 84.8774],

            'banke nepalgunj' => [28.0500, 81.6167],
            'banke kohalpur' => [28.1985, 81.6924],
            'banke khajura' => [28.1000, 81.5500],
            'banke banke bazaar' => [28.0500, 81.6167],

            'sunsari itahari' => [26.6667, 87.2833],
            'sunsari dharan' => [26.8125, 87.2833],
            'sunsari inaruwa' => [26.6060, 87.1500],
            'sunsari duhabhi' => [26.7000, 87.2700],
            'sunsari sunsari bazaar' => [26.6667, 87.2833],

            'kailali dhangadhi' => [28.7014, 80.5898],
            'kailali tikapur' => [28.5000, 81.1333],
            'kailali lamki' => [28.6200, 81.1400],
            'kailali attariya' => [28.8000, 80.5800],
            'kailali kailali bazaar' => [28.7014, 80.5898],
        ];

        if (isset($map[$key])) {
            return [
                'latitude' => $map[$key][0],
                'longitude' => $map[$key][1],
            ];
        }

        $districtOnly = [
            'kathmandu' => [27.7172, 85.3240],
            'lalitpur' => [27.6727, 85.3253],
            'bhaktapur' => [27.6710, 85.4298],
            'kaski' => [28.2096, 83.9856],
            'morang' => [26.4525, 87.2718],
            'dhading' => [27.9711, 84.8985],
            'chitwan' => [27.6768, 84.4359],
            'rupandehi' => [27.7006, 83.4484],
            'parsa' => [27.0104, 84.8774],
            'banke' => [28.0500, 81.6167],
            'sunsari' => [26.6667, 87.2833],
            'kailali' => [28.7014, 80.5898],
        ];

        $districtLower = Str::lower($district);

        if (isset($districtOnly[$districtLower])) {
            return [
                'latitude' => $districtOnly[$districtLower][0],
                'longitude' => $districtOnly[$districtLower][1],
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function branchCode(string $name, string $prefix): string
    {
        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', Str::ascii($name)));

        return 'NP-' . $prefix . '-' . substr($slug, 0, 14);
    }

    private function phoneFromText(string $text): string
    {
        $number = abs(crc32($text));
        $suffix = str_pad((string) ($number % 100000000), 8, '0', STR_PAD_LEFT);

        return '98' . $suffix;
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }

    private function districtsWithCities(): array
    {
        return [
            'Taplejung' => ['Phungling', 'Suketar', 'Dobhan'],
            'Panchthar' => ['Phidim', 'Yangnam', 'Rabi'],
            'Ilam' => ['Ilam Bazaar', 'Fikkal', 'Pashupatinagar'],
            'Jhapa' => ['Birtamod', 'Damak', 'Mechinagar', 'Kakarbhitta'],
            'Morang' => ['Biratnagar', 'Urlabari', 'Belbari', 'Pathari'],
            'Sunsari' => ['Itahari', 'Dharan', 'Inaruwa', 'Duhabi'],
            'Dhankuta' => ['Dhankuta Bazaar', 'Hile', 'Pakhribas'],
            'Terhathum' => ['Myanglung', 'Jirikhimti', 'Basantapur'],
            'Sankhuwasabha' => ['Khandbari', 'Tumlingtar', 'Chainpur'],
            'Bhojpur' => ['Bhojpur Bazaar', 'Dingla', 'Taksar'],
            'Solukhumbu' => ['Salleri', 'Lukla', 'Namche'],
            'Okhaldhunga' => ['Okhaldhunga Bazaar', 'Rumjatar', 'Manebhanjyang'],
            'Khotang' => ['Diktel', 'Halesi', 'Aiselukharka'],
            'Udayapur' => ['Gaighat', 'Katari', 'Beltar'],
            'Saptari' => ['Rajbiraj', 'Kanchanrup', 'Hanumannagar'],
            'Siraha' => ['Lahan', 'Siraha Bazaar', 'Mirchaiya'],
            'Dhanusha' => ['Janakpur', 'Dhalkebar', 'Mahendranagar'],
            'Mahottari' => ['Jaleshwar', 'Bardibas', 'Gaushala'],
            'Sarlahi' => ['Malangwa', 'Lalbandi', 'Hariwan'],
            'Rautahat' => ['Gaur', 'Chandrapur', 'Garuda'],
            'Bara' => ['Kalaiya', 'Simara', 'Nijgadh'],
            'Parsa' => ['Birgunj', 'Pokhariya', 'Thori'],
            'Dolakha' => ['Charikot', 'Jiri', 'Mainapokhari'],
            'Ramechhap' => ['Manthali', 'Ramechhap Bazaar', 'Khimti'],
            'Sindhuli' => ['Sindhulimadhi', 'Dudhauli', 'Bhurungi'],
            'Kavrepalanchok' => ['Dhulikhel', 'Banepa', 'Panauti'],
            'Sindhupalchok' => ['Chautara', 'Melamchi', 'Barhabise'],
            'Kathmandu' => ['Tripureshwor', 'New Road', 'Baneshwor', 'Kalanki', 'Chabahil', 'Koteshwor', 'Balaju'],
            'Lalitpur' => ['Patan', 'Jawalakhel', 'Lagankhel', 'Satdobato'],
            'Bhaktapur' => ['Bhaktapur Durbar Square', 'Suryabinayak', 'Thimi', 'Duwakot'],
            'Nuwakot' => ['Bidur', 'Trishuli', 'Battar'],
            'Rasuwa' => ['Dhunche', 'Syabrubesi', 'Timure'],
            'Dhading' => ['Dhading Besi', 'Malekhu', 'Gajuri'],
            'Makwanpur' => ['Hetauda', 'Manahari', 'Thaha'],
            'Chitwan' => ['Bharatpur', 'Narayanghat', 'Tandi', 'Mugling'],
            'Gorkha' => ['Gorkha Bazaar', 'Palungtar', 'Aabukhaireni'],
            'Lamjung' => ['Besisahar', 'Sundarbazar', 'Bhoteodar'],
            'Tanahun' => ['Damauli', 'Dumre', 'Bandipur'],
            'Syangja' => ['Putalibazar', 'Waling', 'Galyang'],
            'Kaski' => ['Pokhara', 'Lakeside', 'Prithvi Chowk', 'Bagar'],
            'Manang' => ['Chame', 'Manang Village', 'Dharapani'],
            'Mustang' => ['Jomsom', 'Kagbeni', 'Marpha'],
            'Myagdi' => ['Beni', 'Galeshwor', 'Darbang'],
            'Parbat' => ['Kusma', 'Phalebas', 'Dimuwa'],
            'Baglung' => ['Baglung Bazaar', 'Burtibang', 'Galkot'],
            'Nawalpur' => ['Kawasoti', 'Gaindakot', 'Daldale'],
            'Rupandehi' => ['Butwal', 'Bhairahawa', 'Tilottama', 'Devdaha'],
            'Kapilvastu' => ['Taulihawa', 'Krishnanagar', 'Chandrauta'],
            'Palpa' => ['Tansen', 'Rampur', 'Aryabhanjyang'],
            'Arghakhanchi' => ['Sandhikharka', 'Thada', 'Balkot'],
            'Gulmi' => ['Tamghas', 'Ridi', 'Wami'],
            'Rukum East' => ['Rukumkot', 'Sisne', 'Lukums'],
            'Rolpa' => ['Liwang', 'Sulichaur', 'Holeri'],
            'Pyuthan' => ['Pyuthan Khalanga', 'Bijuwar', 'Bhingri'],
            'Dang' => ['Ghorahi', 'Tulsipur', 'Lamahi'],
            'Banke' => ['Nepalgunj', 'Kohalpur', 'Khajura'],
            'Bardiya' => ['Gulariya', 'Rajapur', 'Bansgadhi'],
            'Rukum West' => ['Musikot', 'Chaurjahari', 'Aathbiskot'],
            'Salyan' => ['Salyan Khalanga', 'Shrinagar', 'Kapurkot'],
            'Dolpa' => ['Dunai', 'Juphal', 'Tripurakot'],
            'Jumla' => ['Jumla Bazaar', 'Khalanga', 'Tila'],
            'Mugu' => ['Gamgadhi', 'Rara', 'Soru'],
            'Humla' => ['Simikot', 'Yalbang', 'Sarkegad'],
            'Kalikot' => ['Manma', 'Raskot', 'Khandachakra'],
            'Jajarkot' => ['Khalanga', 'Bheri', 'Chhedagad'],
            'Dailekh' => ['Dailekh Bazaar', 'Dullu', 'Naumule'],
            'Surkhet' => ['Birendranagar', 'Chhinchu', 'Gurbhakot'],
            'Bajura' => ['Martadi', 'Kolti', 'Budhiganga'],
            'Bajhang' => ['Chainpur', 'Bungal', 'Jayaprithvi'],
            'Achham' => ['Mangalsen', 'Sanphebagar', 'Kamalbazaar'],
            'Doti' => ['Dipayal', 'Silgadhi', 'Budhar'],
            'Kailali' => ['Dhangadhi', 'Tikapur', 'Lamki', 'Attariya'],
            'Kanchanpur' => ['Mahendranagar', 'Belauri', 'Punarbas'],
            'Dadeldhura' => ['Dadeldhura Bazaar', 'Amargadhi', 'Jogbudha'],
            'Baitadi' => ['Dasharathchand', 'Patan', 'Melauli'],
            'Darchula' => ['Khalanga', 'Gokuleshwar', 'Malikarjun'],
        ];
    }
}