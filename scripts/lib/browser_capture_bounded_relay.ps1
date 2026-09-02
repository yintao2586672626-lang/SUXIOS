param([Parameter(Mandatory = $true)][string]$ConfigPath)

$ErrorActionPreference = 'Stop'
$config = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json

Add-Type -TypeDefinition @'
using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading;

public static class SuxiBrowserBoundedRelay
{
    private static readonly object Gate = new object();
    private static long stdoutBytes;
    private static long stderrBytes;
    private static long writtenBytes;
    private static bool exceeded;

    public static int Run(string executable, string[] args, string cwd, string stdoutPath, string stderrPath, string metadataPath, long limit)
    {
        stdoutBytes = 0;
        stderrBytes = 0;
        writtenBytes = 0;
        exceeded = false;
        SuxiBrowserBoundedRelay.metadataPath = metadataPath;
        limit = Math.Max(1, limit);
        var start = new ProcessStartInfo {
            FileName = executable,
            Arguments = JoinArguments(args),
            WorkingDirectory = cwd,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        using (var process = new Process { StartInfo = start })
        using (var stdout = new FileStream(stdoutPath, FileMode.Create, FileAccess.Write, FileShare.ReadWrite))
        using (var stderr = new FileStream(stderrPath, FileMode.Create, FileAccess.Write, FileShare.ReadWrite)) {
            if (!process.Start()) return 125;
            process.StandardInput.Close();
            var stdoutThread = new Thread(() => Pump(process.StandardOutput.BaseStream, stdout, true, limit, process.Id));
            var stderrThread = new Thread(() => Pump(process.StandardError.BaseStream, stderr, false, limit, process.Id));
            stdoutThread.IsBackground = true;
            stderrThread.IsBackground = true;
            stdoutThread.Start();
            stderrThread.Start();
            process.WaitForExit();
            stdoutThread.Join(5000);
            stderrThread.Join(5000);
            WriteMetadata(metadataPath);
            return exceeded ? 122 : process.ExitCode;
        }
    }

    private static void Pump(Stream input, FileStream output, bool isStdout, long limit, int childPid)
    {
        var buffer = new byte[8192];
        try {
            while (true) {
                var read = input.Read(buffer, 0, buffer.Length);
                if (read <= 0) break;
                var shouldKill = false;
                lock (Gate) {
                    if (isStdout) stdoutBytes += read; else stderrBytes += read;
                    var remaining = Math.Max(0, limit - writtenBytes);
                    var write = (int)Math.Min(read, remaining);
                    if (write > 0) {
                        output.Write(buffer, 0, write);
                        output.Flush();
                        writtenBytes += write;
                    }
                    exceeded = stdoutBytes + stderrBytes > limit;
                    shouldKill = exceeded;
                    WriteMetadataUnlocked(null);
                }
                if (shouldKill) {
                    KillTree(childPid);
                    break;
                }
            }
        } catch {
            KillTree(childPid);
        }
    }

    private static string metadataPath;

    private static void WriteMetadata(string path)
    {
        lock (Gate) {
            metadataPath = path;
            WriteMetadataUnlocked(path);
        }
    }

    private static void WriteMetadataUnlocked(string path)
    {
        if (!String.IsNullOrEmpty(path)) metadataPath = path;
        if (String.IsNullOrEmpty(metadataPath)) return;
        File.WriteAllText(metadataPath, stdoutBytes + "|" + stderrBytes + "|" + (exceeded ? "1" : "0"), Encoding.ASCII);
    }

    private static void KillTree(int pid)
    {
        try {
            using (var killer = Process.Start(new ProcessStartInfo {
                FileName = "taskkill.exe",
                Arguments = "/PID " + pid + " /T /F",
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            })) {
                if (killer != null) killer.WaitForExit(5000);
            }
        } catch { }
    }

    private static string JoinArguments(string[] args)
    {
        if (args == null || args.Length == 0) return "";
        var result = new StringBuilder();
        foreach (var value in args) {
            if (result.Length > 0) result.Append(' ');
            result.Append(Quote(value ?? ""));
        }
        return result.ToString();
    }

    private static string Quote(string value)
    {
        if (value.Length > 0 && value.IndexOfAny(new[] { ' ', '\t', '\n', '\v', '"' }) < 0) return value;
        var result = new StringBuilder("\"");
        var slashes = 0;
        foreach (var ch in value) {
            if (ch == '\\') { slashes++; continue; }
            if (ch == '"') {
                result.Append('\\', slashes * 2 + 1).Append(ch);
                slashes = 0;
                continue;
            }
            result.Append('\\', slashes).Append(ch);
            slashes = 0;
        }
        result.Append('\\', slashes * 2).Append('"');
        return result.ToString();
    }
}
'@

$arguments = @($config.args | ForEach-Object { [string]$_ })
$exitCode = [SuxiBrowserBoundedRelay]::Run(
    [string]$config.executable,
    [string[]]$arguments,
    [string]$config.cwd,
    [string]$config.stdout_path,
    [string]$config.stderr_path,
    [string]$config.metadata_path,
    [long]$config.limit_bytes
)
exit $exitCode
