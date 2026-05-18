<?php
session_start();
require_once __DIR__ . '/../lib/functions.php';

$config = load_config(false);
$login_enabled = filter_var($config['security']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!$login_enabled) {
    header('Location: ../index.php');
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
            save_config_file($config, __DIR__ . '/../conf/config.ini');
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $new_username;
            header('Location: ../index.php');
            exit;
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === $correct_username && hash('sha512', $password) === $correct_password) {
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            header('Location: ../index.php');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_setup ? 'Configurar Acceso' : 'Login'; ?> - IP Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-950 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900 via-gray-950 to-black flex items-center justify-center min-h-screen p-4 sm:p-6">
    <div class="max-w-md w-full bg-gray-900/60 backdrop-blur-xl rounded-3xl shadow-2xl p-6 sm:p-8 border border-gray-800/80 transition-all duration-300">
        <div class="text-center mb-8">
            <img src="../assets/logo.png" class="w-16 h-16 sm:w-20 sm:h-20 mx-auto mb-4 rounded-2xl shadow-lg border border-gray-800/60 bg-gray-950/40 p-1">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-indigo-200 to-white tracking-tight">
                <?php echo $is_setup ? 'Configuración Inicial' : 'Acceso Restringido'; ?>
            </h2>
            <p class="text-gray-400 text-xs sm:text-sm mt-2 font-medium">
                <?php echo $is_setup ? 'Establece tus credenciales para proteger tu panel' : 'Introduce tus credenciales para continuar'; ?>
            </p>
        </div>

        <form method="POST" class="space-y-4 sm:space-y-5">
            <?php if ($is_setup): ?>
                <div>
                    <label class="block text-gray-400 text-[11px] uppercase tracking-wider font-semibold mb-1.5 ml-1">Nombre de Usuario</label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-500 group-focus-within:text-blue-400 transition-colors">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <input type="text" name="new_username" required autofocus
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-950/40 border border-gray-800 text-white placeholder-gray-500 focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-300 text-sm"
                            placeholder="username">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-400 text-[11px] uppercase tracking-wider font-semibold mb-1.5 ml-1">Nueva Contraseña</label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-500 group-focus-within:text-blue-400 transition-colors">
                            <i class="fas fa-lock text-sm"></i>
                        </div>
                        <input type="password" name="new_password" required
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-950/40 border border-gray-800 text-white placeholder-gray-500 focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-300 text-sm"
                            placeholder="Contraseña robusta">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-400 text-[11px] uppercase tracking-wider font-semibold mb-1.5 ml-1">Confirmar Contraseña</label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-500 group-focus-within:text-blue-400 transition-colors">
                            <i class="fas fa-shield-halved text-sm"></i>
                        </div>
                        <input type="password" name="confirm_password" required
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-950/40 border border-gray-800 text-white placeholder-gray-500 focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-300 text-sm"
                            placeholder="Repite la contraseña">
                    </div>
                </div>
            <?php else: ?>
                <div>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-500 group-focus-within:text-blue-400 transition-colors">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <input type="text" name="username" required autofocus
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-950/40 border border-gray-800 text-white placeholder-gray-500 focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-300 text-sm"
                            placeholder="Usuario">
                    </div>
                </div>
                <div>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-500 group-focus-within:text-blue-400 transition-colors">
                            <i class="fas fa-lock text-sm"></i>
                        </div>
                        <input type="password" name="password" required
                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-gray-950/40 border border-gray-800 text-white placeholder-gray-500 focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-300 text-sm"
                            placeholder="Contraseña">
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="p-3 rounded-xl bg-red-950/30 border border-red-900/50 text-red-400 text-xs text-center font-medium flex items-center justify-center gap-2">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <button type="submit"
                class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-500/10 hover:shadow-blue-500/25 transform active:scale-[0.98] transition-all duration-200 text-sm mt-2">
                <?php echo $is_setup ? 'Guardar y Continuar' : 'Entrar al Dashboard'; ?>
            </button>
        </form>
    </div>
</body>

</html>