<h1 class="h2">About KitePHP</h1>

<p>KitePHP is a lightweight, developer-friendly MVC framework and Content Management System built to restore simplicity to modern PHP web development. Designed with a zero-friction philosophy, KitePHP bridges the gap between structured Model-View-Controller architecture and effortless deployment.</p>
<h2>Why KitePHP Was Created</h2>
<p>Modern PHP development with heavyweights like Laravel and CodeIgniter has evolved significantly, but it comes with deployment overhead that isn't ideal for every host environment:</p>
<ul>
	<li>
		Deployment Overhead: Modern frameworks depend heavily on SSH terminal access, Composer dependency management, root path configuration (/public), symlinks, and modern server extensions—features frequently restricted or unsupported on standard shared hosting or free web hosts (e.g., InfinityFree, 000webhost, cPanel shared tiers).
	</li>
	<li>
		Configuration Complexity: Routing setups, .htaccess rewrites, and environment files (.env) in mainstream frameworks often break on shared hosting without custom modifications or dedicated document root control.
	</li>
	<li>
		Over-Engineering for Small-to-Medium Projects: Setting up a full framework stack, running build tools, and executing migration scripts just to deploy a client site or personal project often creates unnecessary friction.
	</li>
</ul>
<h2>KitePHP solves this Directly</h2>
<ul>
<li>
	Upload & Run Simplicity: Built to run out-of-the-box via traditional FTP/file manager upload without requiring command-line access or SSH.
</li>
<li>
	Shared-Hosting Native: Configured out of the box to work seamlessly in traditional public directory environments, subfolders, or basic cPanel account layouts.
</li>
<li>
	Clean MVC Architecture: Retains clean code separation (Models, Views, Controllers) and CMS capabilities without bloat, giving you structure without modern deployment headaches.
</li>	
</ul>
<p><a href="<?= url('/posts/23') ?>">About the Developer</a></p>


<p class="lead">Request → <code>index.php</code> → Router → Controller → Model → View → Response.</p>
<p class="text-body-secondary">Try <a href="<?= url('/hello/KitePHP') ?>">/hello/KitePHP</a> for a closure route.</p>
