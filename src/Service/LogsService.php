<?php
declare(strict_types=1);
namespace Module\UpdateStock\Service;





class LogsService
{

    private static $updateStockVersion = "1.0.8";


    public static function log($message, $severity = 'INFO', $sendToPSLogs = false)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = '[' . $timestamp . '] [' . $severity . '] [updatestock v' . self::$updateStockVersion . '] ' . $message . "\n";
        file_put_contents(dirname(__DIR__, 2) . '/updatestock.log', $logMessage, FILE_APPEND);
        if ($sendToPSLogs) {
            switch ($severity) {
                case "INFO":
                    $psSeverity = 1;
                    break;
                case "WARN":
                    $psSeverity = 2;
                    break;
                case "ERROR":
                    $psSeverity = 3;
                    break;
                case "CRITICAL":
                    $psSeverity = 4;
                    break; // Added missing break for CRITICAL
                default:
                    $psSeverity = 1;
            }
            \PrestaShopLogger::addLog($message, $psSeverity);
        }
    }

    public static function readLastLines($file, $lines = 30)
    {
        $f = fopen($file, "r");
        if (!$f) {
            return false;
        }

        $pos = -2;
        $eof = "";
        $result = [];

        fseek($f, $pos, SEEK_END);
        $caracter = fgetc($f);

        while ($lines > 0) {
            if ($caracter === "\n") {
                $lines--;
            }

            $eof = $caracter . $eof;
            $pos--;

            if (fseek($f, $pos, SEEK_END) === -1) {
                rewind($f);
                break;
            }

            $caracter = fgetc($f);
        }

        while (($line = fgets($f)) !== false) {
            $linea = trim($line);
            if ($linea !== '') {
                $result[] = $linea;
            }
        }

        fclose($f);

        return $result;
    }

    /**
     * Obtiene el tamaño de un archivo en una unidad legible por humanos.
     *
     * @param string $file Ruta del archivo.
     * @param int $decimals Número de decimales a mostrar (opcional).
     * @return string Tamaño del archivo con la unidad correspondiente.
     */
    public static function getFileSize($file, $decimals = 2)
    {
        // Verificar si el archivo existe
        if (!file_exists($file)) {
            return "0 B"; // Changed from returning false to string as per type hint/docblock implication
        }

        // Obtener el tamaño del archivo en bytes
        $tamanoBytes = filesize($file);

        // Definir las unidades posibles
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];

        // Calcular el índice de la unidad correspondiente
        $indice = 0;
        while ($tamanoBytes >= 1024 && $indice < count($unidades) - 1) {
            $tamanoBytes /= 1024;
            $indice++;
        }

        // Formatear el tamaño con el número de decimales especificado
        return round($tamanoBytes, $decimals) . ' ' . $unidades[$indice];
    }
}
