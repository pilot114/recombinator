for domain in quick-slot-casino.nl huff-n-puffslot.de; do
    echo "===== $domain ====="
    for type in A AAAA NS MX TXT CAA; do
        echo "--- $type ---"
        dig "$domain" "$type" +noall +answer
    done
done
