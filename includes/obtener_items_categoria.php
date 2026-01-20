<?php
require_once 'database.php';

if (isset($_GET['categoria_id'])) {
    $categoria_id = intval($_GET['categoria_id']);
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT nombre, stock, stock_minimo, descripcion 
              FROM items 
              WHERE categoria_id = :categoria_id 
              ORDER BY nombre";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':categoria_id', $categoria_id);
    $stmt->execute();
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($items) > 0) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-striped table-sm">';
        echo '<thead><tr><th>Item</th><th>Stock</th><th>Mínimo</th><th>Descripción</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($items as $item) {
            $claseStock = ($item['stock'] <= $item['stock_minimo']) ? 'stock-critico' : '';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($item['nombre']) . '</td>';
            echo '<td class="' . $claseStock . '">' . $item['stock'] . '</td>';
            echo '<td>' . $item['stock_minimo'] . '</td>';
            echo '<td>' . htmlspecialchars($item['descripcion']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table></div>';
    } else {
        echo '<div class="alert alert-info">No hay items en esta categoría</div>';
    }
} else {
    echo '<div class="alert alert-danger">ID de categoría no especificado</div>';
}
?>