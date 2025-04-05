<?php
header('Content-Type: application/json'); // Siempre devolver JSON

$dataFile = 'meetings.json'; // Archivo donde se guardarán los datos

// --- Funciones Helper ---

// Función para leer los datos del archivo JSON
function getMeetings() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        // Si el archivo no existe, crearlo con un array vacío
        file_put_contents($dataFile, json_encode([]));
        return [];
    }
    $jsonData = file_get_contents($dataFile);
    $meetings = json_decode($jsonData, true); // Decodificar como array asociativo

    // Verificar si la decodificación fue exitosa y si es un array
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($meetings)) {
        // Si hay error o no es array, retornar array vacío y loguear error
        error_log("Error decodificando JSON o el archivo no contiene un array: " . json_last_error_msg());
        return []; // Devolver array vacío para evitar errores posteriores
    }

    return $meetings;
}

// Función para guardar los datos en el archivo JSON
function saveMeetings($meetings) {
    global $dataFile;
    // Intentar codificar a JSON y guardar
    $jsonData = json_encode($meetings, JSON_PRETTY_PRINT); // JSON_PRETTY_PRINT para legibilidad
    if (json_last_error() !== JSON_ERROR_NONE) {
         error_log("Error codificando datos a JSON: " . json_last_error_msg());
         return false; // Indicar fallo
    }
    // file_put_contents devuelve false en caso de error
    return file_put_contents($dataFile, $jsonData) !== false;
}

// --- Lógica Principal del API ---

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Acción no válida']; // Respuesta por defecto

if ($method === 'GET') {
    // Simplemente devolver todas las reuniones
    $meetings = getMeetings();
    // No necesitamos 'success' aquí, solo devolvemos el array (o array vacío si hay error)
    echo json_encode($meetings);
    exit; // Terminar script después de enviar respuesta GET

} elseif ($method === 'POST') {
    // Obtener el cuerpo de la solicitud POST (que debería ser JSON)
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['action'])) {
        $response['message'] = 'Datos de entrada inválidos o acción no especificada.';
        echo json_encode($response);
        exit;
    }

    $action = $input['action'];
    $meetings = getMeetings(); // Cargar reuniones existentes

    try {
        switch ($action) {
            case 'add':
                if (!isset($input['meeting']) || !is_array($input['meeting']) || !isset($input['meeting']['id'])) {
                     throw new Exception('Datos de reunión incompletos para añadir.');
                }
                 // Validar datos básicos (puedes añadir más validaciones)
                if (empty($input['meeting']['date']) || empty($input['meeting']['name']) || empty($input['meeting']['responsible'])) {
                    throw new Exception('Faltan campos obligatorios: fecha, tema o responsable.');
                }

                $newMeeting = $input['meeting'];
                // Asegurarse de que no exista ya un meeting con el mismo ID (poco probable con el método JS, pero buena práctica)
                $existingIndex = array_search($newMeeting['id'], array_column($meetings, 'id'));
                if ($existingIndex !== false) {
                     throw new Exception('Ya existe una reunión con este ID.');
                }

                $meetings[] = $newMeeting; // Añadir la nueva reunión al array

                if (saveMeetings($meetings)) {
                    $response = ['success' => true, 'message' => 'Reunión añadida.', 'meeting' => $newMeeting];
                } else {
                     throw new Exception('Error al guardar el archivo de reuniones.');
                }
                break;

            case 'update':
                 if (!isset($input['meeting']) || !is_array($input['meeting']) || !isset($input['meeting']['id'])) {
                     throw new Exception('Datos de reunión incompletos para actualizar.');
                }
                 // Validar datos básicos
                if (empty($input['meeting']['date']) || empty($input['meeting']['name']) || empty($input['meeting']['responsible'])) {
                    throw new Exception('Faltan campos obligatorios: fecha, tema o responsable.');
                }

                $updatedMeeting = $input['meeting'];
                $meetingIdToUpdate = $updatedMeeting['id'];
                $found = false;

                foreach ($meetings as $index => $meeting) {
                    if ($meeting['id'] === $meetingIdToUpdate) {
                        $meetings[$index] = $updatedMeeting; // Reemplazar el meeting existente
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new Exception('No se encontró la reunión para actualizar.');
                }

                if (saveMeetings($meetings)) {
                    $response = ['success' => true, 'message' => 'Reunión actualizada.', 'meeting' => $updatedMeeting];
                } else {
                    throw new Exception('Error al guardar el archivo de reuniones.');
                }
                break;

            case 'delete':
                if (!isset($input['id'])) {
                    throw new Exception('ID de reunión no proporcionado para eliminar.');
                }
                $meetingIdToDelete = $input['id'];
                $initialCount = count($meetings);

                // Filtrar el array, manteniendo solo los meetings cuyo ID NO coincida
                $meetings = array_filter($meetings, function($meeting) use ($meetingIdToDelete) {
                    return $meeting['id'] !== $meetingIdToDelete;
                });

                // Reindexar el array para evitar huecos (opcional, pero bueno para JSON)
                $meetings = array_values($meetings);

                if (count($meetings) === $initialCount) {
                     throw new Exception('No se encontró la reunión para eliminar.');
                }


                if (saveMeetings($meetings)) {
                    $response = ['success' => true, 'message' => 'Reunión eliminada.'];
                } else {
                    throw new Exception('Error al guardar el archivo de reuniones.');
                }
                break;

            default:
                $response['message'] = 'Acción desconocida.';
                break;
        }
    } catch (Exception $e) {
        // Capturar cualquier excepción y ponerla en el mensaje de respuesta
        http_response_code(400); // Bad Request (o 500 si es error interno)
        $response['message'] = $e->getMessage();
        error_log("Error en API: " . $e->getMessage()); // Loguear el error en el servidor
    }

    // Enviar la respuesta JSON para acciones POST
    echo json_encode($response);
    exit;

} else {
    // Método no soportado (PUT, DELETE directo, etc.)
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Método HTTP no soportado.';
    echo json_encode($response);
    exit;
}

?>