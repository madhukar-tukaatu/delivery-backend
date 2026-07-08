<?php

namespace Database\Seeders\Helpers;

class NepalData
{
    public static function provinces(): array
    {
        return [
            ['code' => 'KOSHI', 'name' => 'Koshi Province', 'capital' => 'Biratnagar'],
            ['code' => 'MADHESH', 'name' => 'Madhesh Province', 'capital' => 'Janakpur'],
            ['code' => 'BAGMATI', 'name' => 'Bagmati Province', 'capital' => 'Hetauda'],
            ['code' => 'GANDAKI', 'name' => 'Gandaki Province', 'capital' => 'Pokhara'],
            ['code' => 'LUMBINI', 'name' => 'Lumbini Province', 'capital' => 'Deukhuri'],
            ['code' => 'KARNALI', 'name' => 'Karnali Province', 'capital' => 'Birendranagar'],
            ['code' => 'SUDUR', 'name' => 'Sudurpashchim Province', 'capital' => 'Godawari'],
        ];
    }

    public static function districts(): array
    {
        return [
            ['KOSHI','Taplejung','Taplejung'],['KOSHI','Panchthar','Phidim'],['KOSHI','Ilam','Ilam'],['KOSHI','Jhapa','Bhadrapur'],['KOSHI','Morang','Biratnagar'],['KOSHI','Sunsari','Inaruwa'],['KOSHI','Dhankuta','Dhankuta'],['KOSHI','Terhathum','Myanglung'],['KOSHI','Sankhuwasabha','Khandbari'],['KOSHI','Bhojpur','Bhojpur'],['KOSHI','Solukhumbu','Salleri'],['KOSHI','Okhaldhunga','Siddhicharan'],['KOSHI','Khotang','Diktel'],['KOSHI','Udayapur','Gaighat'],
            ['MADHESH','Saptari','Rajbiraj'],['MADHESH','Siraha','Siraha'],['MADHESH','Dhanusha','Janakpur'],['MADHESH','Mahottari','Jaleshwar'],['MADHESH','Sarlahi','Malangwa'],['MADHESH','Rautahat','Gaur'],['MADHESH','Bara','Kalaiya'],['MADHESH','Parsa','Birgunj'],
            ['BAGMATI','Dolakha','Charikot'],['BAGMATI','Sindhupalchok','Chautara'],['BAGMATI','Rasuwa','Dhunche'],['BAGMATI','Dhading','Dhading Besi'],['BAGMATI','Nuwakot','Bidur'],['BAGMATI','Kathmandu','Kathmandu'],['BAGMATI','Bhaktapur','Bhaktapur'],['BAGMATI','Lalitpur','Lalitpur'],['BAGMATI','Kavrepalanchok','Dhulikhel'],['BAGMATI','Ramechhap','Manthali'],['BAGMATI','Sindhuli','Sindhulimadhi'],['BAGMATI','Makwanpur','Hetauda'],['BAGMATI','Chitwan','Bharatpur'],
            ['GANDAKI','Gorkha','Gorkha'],['GANDAKI','Manang','Chame'],['GANDAKI','Mustang','Jomsom'],['GANDAKI','Myagdi','Beni'],['GANDAKI','Kaski','Pokhara'],['GANDAKI','Lamjung','Besisahar'],['GANDAKI','Tanahun','Damauli'],['GANDAKI','Nawalpur','Kawasoti'],['GANDAKI','Syangja','Putalibazar'],['GANDAKI','Parbat','Kusma'],['GANDAKI','Baglung','Baglung'],
            ['LUMBINI','Rukum East','Rukumkot'],['LUMBINI','Rolpa','Liwang'],['LUMBINI','Pyuthan','Pyuthan'],['LUMBINI','Gulmi','Tamghas'],['LUMBINI','Arghakhanchi','Sandhikharka'],['LUMBINI','Palpa','Tansen'],['LUMBINI','Nawalparasi West','Parasi'],['LUMBINI','Rupandehi','Bhairahawa'],['LUMBINI','Kapilvastu','Taulihawa'],['LUMBINI','Dang','Ghorahi'],['LUMBINI','Banke','Nepalgunj'],['LUMBINI','Bardiya','Gulariya'],
            ['KARNALI','Dolpa','Dunai'],['KARNALI','Mugu','Gamgadhi'],['KARNALI','Humla','Simikot'],['KARNALI','Jumla','Jumla'],['KARNALI','Kalikot','Manma'],['KARNALI','Dailekh','Dailekh'],['KARNALI','Jajarkot','Khalanga'],['KARNALI','Rukum West','Musikot'],['KARNALI','Salyan','Salyan Khalanga'],['KARNALI','Surkhet','Birendranagar'],
            ['SUDUR','Bajura','Martadi'],['SUDUR','Bajhang','Chainpur'],['SUDUR','Darchula','Khalanga'],['SUDUR','Baitadi','Dasharathchand'],['SUDUR','Dadeldhura','Dadeldhura'],['SUDUR','Doti','Dipayal'],['SUDUR','Achham','Mangalsen'],['SUDUR','Kailali','Dhangadhi'],['SUDUR','Kanchanpur','Mahendranagar'],
        ];
    }

    public static function municipalitiesFor(string $district, string $hq): array
    {
        $special = [
            'Kathmandu' => ['New Baneshwor','Koteshwor','Kalanki','Balaju','Gongabu','Maharajgunj','Chabahil','Boudha','Thamel','Kalimati','Kapan','Tokha','Nayabazar'],
            'Bhaktapur' => ['Suryabinayak','Madhyapur Thimi','Dadhikot','Kamalbinayak','Jagati','Lokanthali'],
            'Lalitpur' => ['Patan','Satdobato','Gwarko','Lagankhel','Imadol','Bhaisepati','Godawari'],
            'Kaski' => ['Lakeside','Mahendrapool','Prithvi Chowk','Chipledhunga','Birauta','Lekhnath'],
            'Rupandehi' => ['Butwal','Bhairahawa','Tilottama','Murgiya','Devdaha'],
            'Morang' => ['Biratnagar','Itahari Border Area','Urlabari','Pathari','Belbari'],
            'Sunsari' => ['Dharan','Itahari','Inaruwa','Duhabi'],
            'Chitwan' => ['Bharatpur','Narayangadh','Ratnanagar','Tandi','Madi'],
            'Banke' => ['Nepalgunj','Kohalpur','Khajura','Ranjha'],
            'Kailali' => ['Dhangadhi','Tikapur','Lamki','Attariya'],
            'Parsa' => ['Birgunj','Adarshnagar','Murkali','Pokhariya'],
            'Dhanusha' => ['Janakpur','Mithila','Dhalkebar','Janaki'],
            'Jhapa' => ['Birtamod','Damak','Bhadrapur','Mechinagar','Kakarbhitta'],
            'Dang' => ['Ghorahi','Tulsipur','Lamahi','Bhalubang'],
            'Surkhet' => ['Birendranagar','Chhinchu','Sallibazar','Gurbhakot'],
        ];
        return $special[$district] ?? [$hq, $district.' Bazaar', $district.' Road', $district.' Chowk'];
    }

    public static function names(): array
    {
        return ['Aarav Sharma','Sita Thapa','Ramesh Karki','Anita Rai','Bikash Gurung','Prakash Shrestha','Mina Tamang','Suman Adhikari','Puja Basnet','Nabin Magar','Rojina Lama','Dipesh Bhandari','Krishna Chaudhary','Sarita Yadav','Rohan Neupane','Asmita Khadka','Binod Poudel','Sabina Maharjan','Kiran BK','Manisha Ghimire'];
    }

    public static function merchantTypes(): array
    {
        return ['Fashion','Electronics','Cosmetics','Grocery','Handicraft','Pharmacy','Books','Shoes','Mobile Accessories','Home Decor'];
    }
}
