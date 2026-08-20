"""Creates the S3 bucket (if needed) for AWS Textract, tags it, and sets
a 1 day expiration rule
"""

from pathlib import Path

import boto3
from botocore.exceptions import ClientError
from pydantic_settings import BaseSettings, SettingsConfigDict

SERVICE_ROOT = Path(__file__).resolve().parent.parent

JOB_PREFIX = "textract-jobs/"
TAGS = [{"Key": "AppName", "Value": "curriculum-development-tool"}]


class DeploySettings(BaseSettings):
    AWS_REGION: str = "ca-central-1"
    AWS_S3_BUCKET: str = "text-extraction-temp"

    model_config = SettingsConfigDict(
        env_file=str(SERVICE_ROOT / ".env"),
        env_file_encoding="utf-8",
        case_sensitive=True,
        extra="ignore",
    )


def main() -> None:
    settings = DeploySettings()
    region, bucket = settings.AWS_REGION, settings.AWS_S3_BUCKET
    s3 = boto3.client("s3", region_name=region)

    try:
        s3.head_bucket(Bucket=bucket)
        print(f"Bucket '{bucket}' already exists")
    except ClientError as e:
        if e.response["Error"]["Code"] != "404":
            raise
        s3.create_bucket(Bucket=bucket, CreateBucketConfiguration={"LocationConstraint": region})
        s3.get_waiter("bucket_exists").wait(Bucket=bucket)
        print(f"Created bucket '{bucket}'")

    s3.put_bucket_tagging(Bucket=bucket, Tagging={"TagSet": TAGS})
    s3.put_bucket_lifecycle_configuration(
        Bucket=bucket,
        LifecycleConfiguration={"Rules": [{
            "ID": "expire-textract-jobs",
            "Status": "Enabled",
            "Filter": {"Prefix": JOB_PREFIX},
            "Expiration": {"Days": 1},
        }]},
    )
    print(f"Tagged bucket and set 1 day expiration on '{JOB_PREFIX}'")


if __name__ == "__main__":
    main()