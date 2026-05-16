<?php
session_start();
require_once __DIR__ . '/lib/functions.php';

$config = parse_ini_file(__DIR__ . '/conf/config.ini', true);
$login_enabled = filter_var($config['security']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!$login_enabled) {
    header('Location: index.php');
    exit;
}

$correct_username = $config['security']['username'] ?? '';
$correct_password = $config['security']['password'] ?? '';
$is_setup = empty($correct_username) || empty($correct_password);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_setup) {
        $new_username = trim($_POST['new_username'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($new_username)) {
            $error = 'El nombre de usuario no puede estar vacío';
        } elseif (empty($new_password)) {
            $error = 'La contraseña no puede estar vacía';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Las contraseñas no coinciden';
        } else {
            // Save new user and password
            $config['security']['username'] = $new_username;
            $config['security']['password'] = hash('sha512', $new_password);
            save_config_file($config, __DIR__ . '/conf/config.ini');
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $new_username;
            header('Location: index.php');
            exit;
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === $correct_username && hash('sha512', $password) === $correct_password) {
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            header('Location: index.php');
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title><?php echo $is_setup ? 'Configurar Acceso' : 'Login'; ?> - IP Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-900 flex items-center justify-center min-h-screen p-4">
    <div class="max-w-md w-full bg-gray-800 rounded-2xl shadow-2xl p-8 border border-gray-700">
        <div class="text-center mb-8">
            <img src="logo.png" class="w-20 h-20 mx-auto mb-4 rounded-xl shadow-lg">
            <h2 class="text-2xl font-bold text-white">
                <?php echo $is_setup ? 'Configuración Inicial' : 'Acceso Restringido'; ?>
            </h2>
            <p class="text-gray-400 text-sm mt-2">
                <?php echo $is_setup ? 'Establece tus credenciales para proteger tu panel' : 'Introduce tus credenciales para continuar'; ?>
            </p>
        </div>

        <form method="POST" class="space-y-4">
            <?php if ($is_setup): ?>
                <div>
                    <label class="block text-gray-400 text-xs mb-1 ml-1">Nombre de Usuario</label>
                    <input type="text" name="new_username" required autofocus
                        class="w-full p-4 rounded-xl bg-gray-700 border border-gray-600 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"
                        placeholder="username">
                </div>
                <div>
                    <label class="block text-gray-400 text-xs mb-1 ml-1">Nueva Contraseña</label>
                    <input type="password" name="new_password" required
                        class="w-full p-4 rounded-xl bg-gray-700 border border-gray-600 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"
                        placeholder="Contraseña robusta">
                </div>
                <div>
                    <label class="block text-gray-400 text-xs mb-1 ml-1">Confirmar Contraseña</label>
                    <input type="password" name="confirm_password" required
                        class="w-full p-4 rounded-xl bg-gray-700 border border-gray-600 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"
                        placeholder="Repite la contraseña">
                </div>
            <?php else: ?>
                <div>
                    <input type="text" name="username" required autofocus
                        class="w-full p-4 rounded-xl bg-gray-700 border border-gray-600 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"
                        placeholder="Usuario">
                </div>
                <div>
                    <input type="password" name="password" required
                        class="w-full p-4 rounded-xl bg-gray-700 border border-gray-600 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"
                        placeholder="Contraseña">
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <p class="text-red-500 text-xs text-center font-semibold italic"><?php echo $error; ?></p>
            <?php endif; ?>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-lg transform active:scale-95 transition-all">
                <?php echo $is_setup ? 'Guardar y Continuar' : 'Entrar al Dashboard'; ?>
            </button>
        </form>
    </div>
</body>

</html>