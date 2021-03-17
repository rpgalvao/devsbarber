<?php

namespace App\Http\Controllers;

use App\Models\Barber;
use App\Models\BarberAvailability;
use App\Models\BarberPhoto;
use App\Models\BarberService;
use App\Models\BarberTestimonial;
use App\Models\UserAppointment;
use App\Models\UserFavorite;
use Illuminate\Http\Request;

class BarberController extends Controller
{
    private $loggedUser;

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->loggedUser = auth()->user();
    }

    private function searchGeo($address)
    {
        $key = env('MAPS_KEY', null);
        $address = urlencode($address);
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $address . '&key' . $key;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $res = curl_exec($ch);
        curl_close($ch);

        return json_decode($res, true);
    }

    public function list(Request $request)
    {
        $array = ['error' => ''];

        $lat = $request->input('lat');
        $lng = $request->input('lng');
        $city = $request->input('city');
        $offset = $request->input('offset');

        if (empty($offset)) {
            $offset = 0;
        }

        if (!empty($city)) {
            $res = $this->searchGeo($city);

            if (count($res['results']) > 0) {
                $lat = $res['results'][0]['geometry']['location']['lat'];
                $lng = $res['results'][0]['geometry']['location']['lng'];
            }

        } elseif (!empty($lat) && !empty($lng)) {
            $res = $this->searchGeo($lat . ',' . $lng);

            if (count($res['results']) > 0) {
                $city = $res['results'][0]['formatted_address'];
            }

        } else {
            $lat = '-23.5630907';
            $lng = '-46.6682795';
            $city = 'São Paulo';
        }

        $barbers = Barber::select(Barber::raw('*, SQRT(POW(69.1 * (latitude - ' . $lat . '), 2) +
            POW(69.1 * (' . $lng . ' - longitude) * COS(latitude / 57.3), 2)) AS distance'))
            ->havingRaw('distance < ?', [10])
            ->orderBy('distance', 'ASC')
            ->offset($offset)
            ->limit(5)
            ->get();
        foreach ($barbers as $bkey => $bvalue) {
            $barbers[$bkey]['avatar'] = url('media/avatars/' . $barbers[$bkey]['avatar']);
        }

        $array['data'] = $barbers;
        $array['loc'] = 'São Paulo';

        return $array;
    }

    public function one($id)
    {
        $array = ['error' => ''];

        $barber = Barber::find($id);

        if ($barber) {
            $barber['avatar'] = url('media/avatars/' . $barber['avatar']);
            $barber['favorited'] = false;
            $barber['photos'] = [];
            $barber['services'] = [];
            $barber['testimonials'] = [];
            $barber['available'] = [];

//          VERIFICANDO FAVORITO
            $cFavorite = UserFavorite::where('id_user', $this->loggedUser->id)->where('id_barber', $barber->id)->count();

            if ($cFavorite > 0) {
                $barber['favorited'] = true;
            }

//          PEGANDO AS FOTOS DO BARBEIRO
            $barber['photos'] = BarberPhoto::select(['id', 'url'])->where('id_barber', $barber->id)->get();
            foreach ($barber['photos'] as $bpkey => $bpvalue) {
                $barber['photos'][$bpkey]['url'] = url('media/uploads/' . $barber['photos'][$bpkey]['url']);
            }

//          PEGANDO OS SERVIÇOS DO BARBEIRO
            $barber['services'] = BarberService::select(['id', 'name', 'price'])->where('id_barber',
                $barber->id)->get();

//          PEGANDO OS DEPOIMENTOS DO BARBEIRO
            $barber['testimonials'] = BarberTestimonial::select(['id', 'name', 'rate', 'body'])->where('id_barber',
                $barber->id)->get();

//          PEGANDO A DISPONIBILIDADE DO BARBEIRO
            $availability = [];

//          PEGANDO A DISPONIBILIDADE CRUA
            $avails = BarberAvailability::where('id_barber', $barber->id)->get();
            $availWeekdays = [];
            foreach ($avails as $item) {
                $availWeekdays[$item['weekday']] = explode(',', $item['hours']);
            }

//          PEGAR OS AGENDAMENTOS DOS PROXIMOS 20 DIAS
            $appointments = [];
            $appQuery = UserAppointment::where('id_barber', $barber->id)
                ->whereBetween('ap_datetime', [
                    date('Y-m-d') . '00:00:00',
                    date('y-m-d', strtotime('+20 days')) . ' 23:59:59'
                ])
                ->get();

            foreach ($appQuery as $appItem) {
                $appointments[] = $appItem['ap_datetime'];
            }

            for ($q = 0; $q < 20; $q++) {
                $timeItem = strtotime('+' . $q . ' days');
                $weekday = date('w', $timeItem);

                if (in_array($weekday, array_keys($availWeekdays))) {
                    $hours = [];
                    $dayItem = date('Y-m-d', $timeItem);

                    foreach ($availWeekdays[$weekday] as $hourItem) {
                        $dayFormated = $dayItem . ' ' . $hourItem . ':00';
                        if (!in_array($dayFormated, $appointments)) {
                            $hours[] = $hourItem;
                        }
                    }

                    if (count($hours) > 0) {
                        $availability[] = [
                            'date' => $dayItem,
                            'hours' => $hours
                        ];
                    }
                }

            }

            $barber['available'] = $availability;

            $array['data'] = $barber;
        } else {
            $array['error'] = 'Ooooops! Barbeiro não encontrado!';
        }

        return $array;
    }

    public function setAppointment($id, Request $request)
    {
        $array = ['error' => ''];

        $service = $request->input('service');
        $year = intval($request->input('year'));
        $month = intval($request->input('month'));
        $day = intval($request->input('day'));
        $hour = intval($request->input('hour'));

        $month = ($month < 10) ? '0' . $month : $month;
        $day = ($day < 10) ? '0' . $day : $day;
        $hour = ($hour < 10) ? '0' . $hour : $hour;

//        1. Verificar se o serviço do barbeiro existe
        $barberService = BarberService::select()
            ->where('id', $service)
            ->where('id_barber', $id)
            ->first();

        if ($barberService) {
//        2. Verificar se a data é real
            $apDate = $year.'-'.$month.'-'.$day.' '.$hour.':00:00';

            if(strtotime($apDate) > 0){
    //        3. Verificar se o barbeiro já possui agendamento nesse dia/hora
                $apps = UserAppointment::select()
                    ->where('id_barber', $id)
                    ->where('ap_datetime', $apDate)
                    ->count();

                if($apps == 0){
        //        4.1 Verificar se o barbeiro atende nesse dia da semana
                    $weekday = date('w', strtotime($apDate));

                    $avail = BarberAvailability::select()
                        ->where('id_barber', $id)
                        ->where('weekday', $weekday)
                        ->first();

                    if($avail) {
//                    4.2 Verificar se o barbeiro atende nesta hora
                        $hours = explode(',', $avail['hours']);
                        if(in_array($hour.':00', $hours)){
//                        5. Fazer o agendamento
                            $newApp = new UserAppointment();
                            $newApp->id_user = $this->loggedUser->id;
                            $newApp->id_barber = $id;
                            $newApp->id_service = $service;
                            $newApp->ap_datetime = $apDate;
                            $newApp->save();
                        } else {
                            $array['error'] = 'Profissional não atende nessa hora, ou já foi agendada!';
                        }
                    } else {
                        $array['error'] = 'Profissional não atende nesse dia!';
                    }
                } else {
                    $array['error'] = 'Profissional indisponível para essa data!';
                }
            } else {
                $array['error'] = 'Data inválida!';
            }
        } else {
            $array['error'] = 'Serviço inexistente!';
        }

        return $array;
    }


    /*
     * CRIAÇÃO DE BARBEIROS ALEATÓRIOS
     *
        public function createRandom()
        {
            $array = ['error' => ''];
            for ($q = 0; $q < 15; $q++) {
                $names = [
                    'Bonieky',
                    'Paulo',
                    'Pedro',
                    'Amanda',
                    'Leticia',
                    'Gabriel',
                    'Gabriela',
                    'Thais',
                    'Luiz',
                    'Diogo',
                    'José',
                    'Jeremias',
                    'Francisco',
                    'Dirce',
                    'Marcelo',
                    'Tiago'
                ];
                $lastnames = [
                    'Santos',
                    'Silva',
                    'Santos',
                    'Silva',
                    'Alvaro',
                    'Sousa',
                    'Diniz',
                    'Josefa',
                    'Luiz',
                    'Diogo',
                    'Limoeiro',
                    'Santos',
                    'Limiro',
                    'Galvão',
                    'Nazare',
                    'Mimoza'
                ];
                $servicos = ['Corte', 'Pintura', 'Aparação', 'Enfeite'];
                $servicos2 = ['Cabelo', 'Unha', 'Pernas', 'Sobrancelhas'];
                $depos = [
                    'Lorem ipsum dolor sit amet consectetur adipisicing elit. Voluptate consequatur tenetur facere voluptatibus iusto accusantium vero sunt, itaque nisi esse ad temporibus a rerum aperiam cum quaerat quae quasi unde.',
                    'Lorem ipsum dolor sit amet consectetur adipisicing elit. Voluptate consequatur tenetur facere voluptatibus iusto accusantium vero sunt, itaque nisi esse ad temporibus a rerum aperiam cum quaerat quae quasi unde.',
                    'Lorem ipsum dolor sit amet consectetur adipisicing elit. Voluptate consequatur tenetur facere voluptatibus iusto accusantium vero sunt, itaque nisi esse ad temporibus a rerum aperiam cum quaerat quae quasi unde.',
                    'Lorem ipsum dolor sit amet consectetur adipisicing elit. Voluptate consequatur tenetur facere voluptatibus iusto accusantium vero sunt, itaque nisi esse ad temporibus a rerum aperiam cum quaerat quae quasi unde.',
                    'Lorem ipsum dolor sit amet consectetur adipisicing elit. Voluptate consequatur tenetur facere voluptatibus iusto accusantium vero sunt, itaque nisi esse ad temporibus a rerum aperiam cum quaerat quae quasi unde.'
                ];
                $newBarber = new Barber();
                $newBarber->name = $names[rand(0, count($names) - 1)] . ' ' . $lastnames[rand(0, count($lastnames) - 1)];
                $newBarber->avatar = rand(1, 4) . '.png';
                $newBarber->stars = rand(2, 4) . '.' . rand(0, 9);
                $newBarber->latitude = '-23.5' . rand(0, 9) . '30907';
                $newBarber->longitude = '-46.6' . rand(0, 9) . '82759';
                $newBarber->save();
                $ns = rand(3, 6);
                for ($w = 0; $w < 4; $w++) {
                    $newBarberPhoto = new BarberPhoto();
                    $newBarberPhoto->id_barber = $newBarber->id;
                    $newBarberPhoto->url = rand(1, 5) . '.png';
                    $newBarberPhoto->save();
                }
                for ($w = 0; $w < $ns; $w++) {
                    $newBarberService = new BarberService();
                    $newBarberService->id_barber = $newBarber->id;
                    $newBarberService->name = $servicos[rand(0, count($servicos) - 1)] . ' de ' . $servicos2[rand(0,
                            count($servicos2) - 1)];
                    $newBarberService->price = rand(1, 99) . '.' . rand(0, 100);
                    $newBarberService->save();
                }
                for ($w = 0; $w < 3; $w++) {
                    $newBarberTestimonial = new BarberTestimonial();
                    $newBarberTestimonial->id_barber = $newBarber->id;
                    $newBarberTestimonial->name = $names[rand(0, count($names) - 1)];
                    $newBarberTestimonial->rate = rand(2, 4) . '.' . rand(0, 9);
                    $newBarberTestimonial->body = $depos[rand(0, count($depos) - 1)];
                    $newBarberTestimonial->save();
                }
                for ($e = 0; $e < 4; $e++) {
                    $rAdd = rand(7, 10);
                    $hours = [];
                    for ($r = 0; $r < 8; $r++) {
                        $time = $r + $rAdd;
                        if ($time < 10) {
                            $time = '0' . $time;
                        }
                        $hours[] = $time . ':00';
                    }
                    $newBarberAvail = new BarberAvailability();
                    $newBarberAvail->id_barber = $newBarber->id;
                    $newBarberAvail->weekday = $e;
                    $newBarberAvail->hours = implode(',', $hours);
                    $newBarberAvail->save();
                }
            }
            return $array;
        }
    */
}
