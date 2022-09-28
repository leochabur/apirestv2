<?php
    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');

    error_reporting(0);

    if ($_SERVER['REQUEST_METHOD'] == 'GET')
    {
        try
        {

            $conn = mysqli_connect('mariadb-masterbus-trafico.planisys.net', 'c0mbexpuser', 'Mb2013Exp', 'c0mbexport');

            $sql = getSqlAllServices();

            $result = mysqli_query($conn, $sql);
            $ordenes = [];
            while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC))
            {
                $ordenes[] = $row;
            }

            mysqli_free_result($result);
            mysqli_close($conn);

            header("HTTP/1.1 200 OK");
            header("Content-Type:application/json");
            header('Access-Control-Allow-Origin: *');

            echo json_encode($ordenes);
            exit();

        }
        catch (Exception $e)
        {
                              header("HTTP/1.1 200 OK");
                              header("Content-Type:application/json");
                              header('Access-Control-Allow-Origin: *');
                              echo json_encode(['status' => 300, 'message' => 'Error inesperado', 'stack' => $e->getMessage()]);
                              exit();
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] == 'POST')
    {
        date_default_timezone_set('America/Argentina/Buenos_Aires');

        $input = json_decode(file_get_contents('php://input'), true);

        $conn = mysqli_connect('mariadb-masterbus-trafico.planisys.net', 'c0mbexpuser', 'Mb2013Exp', 'c0mbexport');

        $result = mysqli_query($conn, getSqlOrden($input['idOrdenTrabajo']));

        $row = mysqli_fetch_array($result);

        mysqli_free_result($result);
        mysqli_close($conn);

        if ($row)
        {
            $llegada = DateTime::createFromFormat('Y-m-d H:i:s', $row['horaLlegada']);
            $salida = DateTime::createFromFormat('Y-m-d H:i:s', $row['horasalida']);
            $now = new DateTime();
         //   $now->sub(new DateInterval('PT3H'));
            
            if ($now > $llegada)
            {
                  header("HTTP/1.1 200 OK");
                  header("Content-Type:application/json");
                  header('Access-Control-Allow-Origin: *');
                  echo json_encode(  ['status' => 301, 'message' => 'El servicio ya ha finalizado']);   
                  exit;
            }
            elseif ($salida > $now) //el servicio aun no ha iniciado, solo deberia devolver la parada mas cercana al usuario
            {
                
                $gpx = simplexml_load_file("$row[gpx_file]");

                $paradas = procesarParadas($gpx, ['x' => $input['posicionPasajero']['latitud'], 'y' => $input['posicionPasajero']['longitud']]); 

                $parada = $paradas[1]; 

                $image = file_get_contents("$row[gpx_file]");

                $base64 = base64_encode($image); 

                $result = [
                  
                            "status" => 200,
                            "mensaje" => "El servicio aun no ha iniciado",
                            "paradaRecomendada" => [
                                                      "nombre" => $parada['name'],
                                                      "latitud" => $parada['point']['x'],
                                                      "longitud" => $parada['point']['y'],
                                                      "tiempoEstimadoArribo" => "0",
                                                      "distanciaEstimadaArribo" => $parada['dist']
                                                    ],
                            "informacionUsuario" => [
                                                      "latitud" => $input['posicionPasajero']['latitud'],
                                                      "longitud" => $input['posicionPasajero']['longitud'],
                                                    ],
                            "gpx" => $base64
                        ];

                $now->add(new DateInterval('PT15M'));

                if ($now >= $salida) //el servicio sale dentro de los proximos15 minutos, debe devolver la posicion e la unidad tambien
                {
                    try
                    {
                        $busPos = getPosInterno($row['interno']);
                        $bus = ['x' => $busPos['x'], 'y' => $busPos['y'], 'posrecta' => 0, 'distancia' => 9999999999];
                        $result["informacionUnidad"] = [
                                                        "latitud" => $busPos['x'], 
                                                        'longitud' => $busPos['y']
                                                        ];
                    }
                    catch (Exception $e){
                                        }                    
                }

                header("HTTP/1.1 200 OK");
                header("Content-Type:application/json");
                header('Access-Control-Allow-Origin: *');
                echo json_encode($result);  
                exit;             
            }
            else
            {
                try
                {
                    $busPos = getPosInterno($row['interno']);
                }
                catch (Exception $e){
                                    }


                $bus = ['x' => $busPos['x'], 'y' => $busPos['y'], 'posrecta' => 0, 'distancia' => 9999999999];

                $lastx = $lasty = $auxx = $auxy = null;
                $index = $enc = 0;
                $auxdist = 9999999999999999;
                $point = null;
                $puntos = [];

                $gpx = simplexml_load_file("$row[gpx_file]");

                $image = file_get_contents("$row[gpx_file]");
                $base64 = base64_encode($image); 

                $posUserX = $input['posicionPasajero']['latitud'];
                $posUserY = $input['posicionPasajero']['longitud'];

                $paradas = procesarParadas($gpx, ['x' => $posUserX, 'y' => $posUserY]); 

                $listaParadas = $paradas[0];    

                $trk = $gpx->trk;

                 foreach ($trk->trkseg as $pt) 
                 {
                      foreach ($pt->trkpt as $p)
                      {
                           $auxx = round((float)$p['lat'], 5);
                           $auxy = round((float)$p['lon'], 5);

                           $puntos[$index] = ['x' => $auxx, 'y' => $auxy, 'dist' => 0];

                           if ($lastx && $lasty)
                           {
                                //Calcula la distancia entre el ultimo punto procesado y el actual
                                $distanceBetweenPoints = distanceGPS($auxx, $auxy, $lastx, $lasty, 'K'); 

                                //Va almacenando increm,entalmente las idtancias obtenidas
                                $puntos[$index]['dist'] = $puntos[($index -1)]['dist'] + ($distanceBetweenPoints * 1000); 

                                //Utilizado para ubicar la posicion del usuario en la recta de puntos 
                                $dist = (distanceGPS($auxx, $auxy, $userx, $usery, 'K') * 1000); 

                                if ($dist < $auxdist)
                                {
                                     $point = $lasty;
                                     $auxdist = $dist;
                                     $enc = $index;
                                }

                                ///por cada recta que compone el recorrido deberia recorrer la lista de paradas para calcular en que posicion debe ubicarla
                                foreach ($listaParadas as $k => $lp)
                                {
                                     $lastDist = (distanceGPS($auxx, $auxy, $lp['point']['x'], $lp['point']['y'], 'K') * 1000); 
                                     if ($lastDist < $lp['dist'])
                                     {
                                          $listaParadas[$k]['dist'] = $lastDist;
                                          $listaParadas[$k]['posrecta'] = $index;
                                     }

                                }

                                ///para cada recta que compone el recorrido debo calcular en que posicion esta ubicada la unida
                                $lastDist = (distanceGPS($auxx, $auxy, $bus['x'], $bus['y'], 'K') * 1000); 
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

                 $paso = false;
                 $parada = null;
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
                                $distancia = (distanceGPS($p['point']['x'], $p['point']['y'], $bus['x'], $bus['y'], 'K') * 1000);
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
                          header("HTTP/1.1 200 OK");
                          header("Content-Type:application/json");
                          header('Access-Control-Allow-Origin: *');
                          return print (json_encode(['status' => 301, 'message' => 'No existen paradas disponibles para el servicio']));   
                          exit;
                      }

                      $indexParada = $p['posrecta'];
                      $indexBus = $bus['posrecta'];
                      $distancia = round((($puntos[$indexParada]['dist'] - $puntos[$indexBus]['dist'])), 2);

                      $result = [
                          
                                    "status" => 200,
                                    "paradaRecomendada" => [
                                                              "nombre" => $parada['name'],
                                                              "latitud" => $parada['point']['x'],
                                                              "longitud" => $parada['point']['y'],
                                                              "tiempoEstimadoArribo" => "0",
                                                              "distanciaEstimadaArribo" => $distancia
                                                            ],
                                    "informacionUsuario" => [
                                                              "latitud" => $posUserX,
                                                              "longitud" => $posUserY
                                                            ],
                                    "informacionUnidad" => [
                                                                "latitud" => $busPos['x'], 
                                                                'longitud' => $busPos['y']
                                                            ],
                                    "gpx" => $base64
                                ];
                 }
                 else
                 {
                    $result = [
                      
                                "status" => 200,
                                "paradaRecomendada" => [
                                                          "nombre" => $parada['name'],
                                                          "latitud" => $parada['point']['x'],
                                                          "longitud" => $parada['point']['y'],
                                                          "tiempoEstimadoArribo" => "0",
                                                          "distanciaEstimadaArribo" => $distancia
                                                        ],
                                "informacionUsuario" => [
                                                          "latitud" => $posUserX,
                                                          "longitud" => $posUserY
                                                        ],
                                "informacionUnidad" => [
                                                            "latitud" => $busPos['x'], 
                                                            'longitud' => $busPos['y']
                                                        ],
                                "gpx" => $base64
                            ];
                 }

                  header("HTTP/1.1 200 OK");
                  header("Content-Type:application/json");
                  header('Access-Control-Allow-Origin: *');
                  return print (json_encode($result));   
                  exit;
            }
        }
        else
        {

            $response = ['status' => 300, 'message' => 'No se encuentra la orden de trabajo con numero '];

              header("HTTP/1.1 200 OK");
              header("Content-Type:application/json");
              header('Access-Control-Allow-Origin: *');
              echo json_encode(  $response);   
              exit();
        }

              header("HTTP/1.1 200 OK");
              header("Content-Type:application/json");
              header('Access-Control-Allow-Origin: *');
              echo json_encode(  ['status' => 301, 'message' => 'Otro tipo de error']);   
              exit;
    }

function getSqlOrden($orden)
{
    return "SELECT hsalidaplantareal as horaSalida,
                                           fservicio as fechaServicio,
                                           ord.id as iOrdenTrabajo,
                                           interno,
                                           s.id_cronograma as idExterno,
                                           if (hllegadaplantareal < hsalidaplantareal,
                                                                                    ADDDATE(CONCAT(fservicio,' ', hllegadaplantareal), INTERVAL 1 DAY),
                                                                                    CONCAT(fservicio,' ', hllegadaplantareal)) as horaLlegada,

                                           if (hsalidaplantareal < hcitacionreal,
                                                                                    ADDDATE(CONCAT(fservicio,' ', hcitacionreal), INTERVAL 1 DAY),
                                                                                    CONCAT(fservicio,' ', hcitacionreal)) as horasalida,

                                            gpx_file
                                    FROM (SELECT borrada, id_micro, nombre, id , hcitacionreal, hsalidaplantareal, fservicio, id_servicio, id_estructura_servicio, hllegadaplantareal
                                          FROM ordenes
                                          WHERE id = $orden) ord

                                    JOIN servicios s ON s.id = ord.id_servicio AND s.id_estructura = ord.id_estructura_servicio
                                    JOIN cronogramas_gpx cgpx ON cgpx.id_cronograma = s.id_cronograma
                                    JOIN unidades u ON u.id = ord.id_micro";
}


function getSqlAllServices()
{
    return "SELECT concat(ord.nombre, ' - ', time_format(hsalidaplantareal, '%H:%i'))  as servicio,
            ord.id as iOrdenTrabajo,
            hcitacionreal as hcitacion,
            hsalidaplantareal as hsalida,
            hllegadaplantareal as hllegada,
            hfinservicioreal as hfinalizacion,
            CONCAT(apellido,', ', emp.nombre) as conductor,
            interno,
            o.ciudad as origen,
            d.ciudad as destino,
            s.id_cronograma as idExterno
            FROM (SELECT id_chofer_1, hcitacionreal, vacio, borrada, id_ciudad_origen, id_ciudad_destino, id_servicio, id_micro, nombre, id, hllegadaplantareal , hsalidaplantareal,
            hfinservicioreal, id_estructura, id_cliente, fservicio, id_estructura_servicio
            FROM ordenes
            WHERE id_estructura = 1 and not borrada and fservicio between DATE_SUB(DATE(NOW()), INTERVAL 1 DAY) AND DATE_ADD(DATE(NOW()), INTERVAL 1 DAY)) ord
            JOIN ciudades o on ord.id_ciudad_origen = o.id
            JOIN ciudades d on d.id = ord.id_ciudad_destino
            LEFT JOIN empleados emp ON emp.id_empleado = ord.id_chofer_1
            JOIN (SELECT i_v, id, id_estructura, id_cronograma from servicios where id_estructura = 1) s ON s.id = ord.id_servicio AND s.id_estructura = ord.id_estructura_servicio
            JOIN unidades u ON u.id = ord.id_micro
            WHERE s.i_v = 'i' AND NOW() BETWEEN DATE_SUB(CONCAT(fservicio,' ', ord.hsalidaplantareal), INTERVAL 120 MINUTE) AND
            DATE_ADD(CONCAT(fservicio,' ', ord.hfinservicioreal), INTERVAL 180 MINUTE) AND
            (id_cliente = 10 OR ord.nombre like '%rondin%') AND vacio = 0 AND borrada = 0 AND
            ord.id_estructura = 1
            ORDER BY ord.nombre";
}

 function distanceGPS($lat1, $lon1, $lat2, $lon2, $unit) 
 {

   // return distancia($lat1, $lon1, $lat2, $lon2);

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
     // return distanceGPS($x1, $y1, $x2, $y2, 'K');

      $moduloRaiz = pow(($x2 - $x1), 2) + pow(($y2 - $y1), 2);
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

function getPosInterno($interno)
{
    if (file_exists("lib/nusoap.php")) 
    {
        require "lib/nusoap.php";
    }
    else 
    {
        throw new Exception("No se encontro el archivo");
    }

    try
    {    
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

function procesarParadas($gpx, $pos)
{
     $listaParadas = [];
     $i = $auxi = 0;
     $dist = 9999999999999;
     foreach ($gpx->wpt as $wpt)
     {
          if ($i < (count($gpx->wpt) -1))
          {
               $px = round((float)$wpt['lat'], 5);
               $py = round((float)$wpt['lon'], 5);

               //return [0 => [$px, $py]];
               $listaParadas[$i] = ['name' => (String)$wpt->name, 'point' => ['x' => $px, 'y' => $py], 'recom' => 0, 'posrecta' => 999999, 'dist' => 999999];
               $auxdist = (distancia($pos['x'], $pos['y'], $px, $py) * 1000);
               if ($auxdist < $dist)
               {
                    $dist = $auxdist;
                    $auxi = $i;
               }
          }
          $i++;
     }
     $listaParadas[$auxi]['recom'] = 1;
     return [0 => $listaParadas, 1 => $listaParadas[$auxi]];
}

?>