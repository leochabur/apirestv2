<?php

function getPosInterno($interno)
{
    if (file_exists("lib/nusoap.php")) {
    require "lib/nusoap.php";
    }
    else {
        throw new Exception("No se encontro el archivo");
    }

    try{
    
    $oSoapSClient = new nusoap_client('https://app.urbetrack.com/App_services/Operation.asmx?wsdl', true);
    $params = array();
    $params['usuario'] = 'masterbus_trafico';
    $params['hash'] = '85CF3EC9C355539B74F36AB7D03BBC1C';
    $params['interno'] = "$interno";
    $resultado = $oSoapSClient->call('ApiGetLocationByVehicle', $params );
    $lati =$resultado['ApiGetLocationByVehicleResult']['Resultado']['Latitud'];
    $long =$resultado['ApiGetLocationByVehicleResult']['Resultado']['Longitud'];
    return ['x' => round((float)$lati,5), 'y' => round((float)$long, 5)];
    }
    catch (Exception $e){ throw new Exception($e->getMessage()); }
}

     function distanceGPS($lat1, $lon1, $lat2, $lon2, $unit) {
      
       $theta = $lon1 - $lon2;
       $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
       $dist = acos($dist);
       $dist = rad2deg($dist);
       $miles = $dist * 60 * 1.1515;
       $unit = strtoupper($unit);
      
       if ($unit == "K") {
         return ($miles * 1.609344);
       } else if ($unit == "N") {
           return ($miles * 0.8684);
         } else {
             return $miles;
           }
     }

     function distancia($x1, $y1, $x2, $y2)
     {
          $moduloRaiz = pow(($x2 - $x1), 2) + pow(($y2 - $y1), 2);

          $moduloRaiz = $moduloRaiz * 1000;
        //  print $moduloRaiz;
         // exit;
         // print sqrt($moduloRaiz)."<br>";
          return sqrt($moduloRaiz);
     }


     function distanciaALaRecta($p1, $p2, $pos)
     {
          $x1 = $p1['x'];
          $y1 = $p1['y'];

          $x2 = $p2['x'];
          $y2 = $p2['y'];

          $px = $pos['x'];
          $py = $pos['y'];

          return distanceGPS($x1, $y1, $px, $py, 'K');
     }

     $file = 'Toyota - Mantenimiento San Miguel - Entrada.gpx';

     $gpx = simplexml_load_file($file);


     $userx = round((float)-34.67356605542864, 5);
     $usery = round((float)-58.72261047363282, 5);


     $lines = [];

     //calcular la parada mas cercana al pasajero que esta realizando la consulta
     $dist = 9999999999999;
     $name = '';
     $pcercanax = $pcercanay = null;

     $listaParadas = [];

     $i = $auxi = 0;

     foreach ($gpx->wpt as $wpt)
     {
          if ($i < (count($gpx->wpt) -1))
          {
               $px = round($wpt['lat'], 5);
               $py = round($wpt['lon'], 5);

               $listaParadas[$i] = ['name' => (String)$wpt->name, 'point' => ['x' => $px, 'y' => $py], 'recom' => 0, 'posrecta' => 999999, 'dist' => 999999];

               $auxdist = distancia($userx, $usery, $px, $py);
               if ($auxdist < $dist)
               {
                    $dist = $auxdist;
                    $name = $wpt->name;
                    $pcercanax = $px;
                    $pcercanay = $py;
                    $auxi = $i;
               }
          }

          $i++;
     }

     $listaParadas[$auxi]['recom'] = 1;

//-33.827885434926685, -59.520057570259524  -34.5472397774814, -58.717718124389656 (26m)

     $bus = ['x' => -34.5472397774814, 'y' => -58.717718124389656, 'posrecta' => 0, 'distancia' => 9999999999];


     $lastx = $lasty = $auxx = $auxy = null;
     $index = $enc = 0;
     $auxdist = 9999999999999999;

     $point = null;

     $puntos = [];

     $gpx = simplexml_load_file($file);

    // print_r($gpx);
     //exit;
     $trk = $gpx->trk;

     foreach ($trk->trkseg as $pt) 
     {
          //print "eeee";
          foreach ($pt->trkpt as $p)
          {
               $auxx = round($p['lat'], 5);
               $auxy = round($p['lon'], 5);

               $puntos[$index] = ['x' => $auxx, 'y' => $auxy, 'dist' => 0];

               if ($lastx && $lasty)
               {
                    //Utiizado para calcular la distancia entre dos puntos 
                    $distanceBetweenPoints = distanceGPS($auxx, $auxy, $lastx, $lasty, 'K');
                    $puntos[$index]['dist'] = $puntos[($index -1)]['dist'] + $distanceBetweenPoints; 

                    //print "sss<br>";

                    $dist = distanciaALaRecta(['x' => $lastx, 'y' => $lasty], ['x' => $auxx, 'y' => $auxy], ['x' => $userx, 'y' => $usery]);

                    if ($dist < $auxdist)
                    {
                         $point = $lasty;
                         $auxdist = $dist;
                         $enc = $index;
                    }

                    ///por cada recta que compone el recorrido deberia recorrer la lista de paradas para calcular en que posicion debe ubicarla
                    foreach ($listaParadas as $k => $lp)
                    {
                         $lastDist = distanciaALaRecta(['x' => $lastx, 'y' => $lasty], ['x' => $auxx, 'y' => $auxy], ['x' => $lp['point']['x'], 'y' => $lp['point']['y']]);
                         if ($lastDist < $lp['dist'])
                         {
                              $listaParadas[$k]['dist'] = $lastDist;
                              $listaParadas[$k]['posrecta'] = $index;
                         }

                    }

                    ///para cada recta que compone el recorrido debo calcular en que posicion esta ubicada la unida
                    $lastDist = distancia( $auxx, $auxy,  $bus['x'], $bus['y']);
                   //  print $lastDist."<br>";
                    if ($lastDist < $bus['distancia'])
                    {
                         $bus['distancia'] = $lastDist;
                         $bus['posrecta'] = $index;
                    }
               }

               $lastx = $auxx;
               $lasty = $auxy;
               $index++;
          }
     }

   //  print_r($bus);
   //  exit;

     $paso = false;
     $parada = "";
     $distancia = "";
     $paradaRecomendada = null;

     foreach ($listaParadas as $k => $p)
     {
          if ($p['recom'])
          {
               $paradaRecomendada = $p;

               if ($bus['posrecta'] >= $p['posrecta'])
               {

                    $paso = true;
               }
               else
               {
                    $parada = $p;
                    $distancia = distanceGPS($p['point']['x'], $p['point']['y'], $bus['x'], $bus['y'], 'K');
                    print "ya pasoooooooooooooooooooo  $bus[posrecta] >= $paradaRecomendada[posrecta]";
                    exit;

               }
          }
     }


     if ($paso)
     {
          $i = 0;
          $seteo = false;

          foreach ($listaParadas as $k => $p)
          {
               if (($p['posrecta'] > $bus['posrecta']) and (!$seteo))
               {
                    $parada = $p;
                    $seteo = true;
               }
               $i++;
          }
          if (!$seteo)
          {
               return print 'No tenes dnde tomar el bus';
          }

          $indexParada = $p['posrecta'];
          $indexBus = $bus['posrecta'];
          $distancia = round((($puntos[$indexParada]['dist'] - $puntos[$indexBus]['dist'])*1000), 2);
     }


     return print "EL BUS ".($paso?'YA PASO':'NO PASO')." $paso - Tu parada recomendada es ".$parada['name']." - El colectivo llega en $distancia";
     


?>
